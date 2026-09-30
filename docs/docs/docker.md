# Running MatrixOne with Docker

- [Standalone (local disk)](#standalone-local-disk)
- [Standalone with S3 storage](#standalone-with-s3-storage)
- [Adding compute nodes (manual scale-out)](#adding-compute-nodes-manual-scale-out)
- [Other deployments](#other-deployments)

Both setups ship as ready-to-use files in the repository's [`docker/`](https://github.com/vuthaihoc/laravel-matrixone/tree/master/docker) directory. The default account is `root` with password `111`; the MySQL-compatible port is `6001`.

## Standalone (local disk)

All MatrixOne services run in one container and store data on the host. This is the right choice for development and for running this package's tests.

`docker/standalone/compose.yml`:

```yaml
services:
  matrixone:
    image: matrixorigin/matrixone:4.2.4
    container_name: matrixone
    hostname: matrixone
    ports:
      - "6001:6001"
    volumes:
      - ./mo-data:/mo-data
    restart: unless-stopped
```

```bash
cd docker/standalone
docker compose up -d
mysql -h 127.0.0.1 -P 6001 -u root -p111 -e "create database laravel"
```

The first start takes up to a minute before port 6001 accepts connections. Everything lives in `./mo-data`; delete that directory to start from scratch.

::: warning Keep the hostname fixed
MatrixOne's log service stores its data under `mo-data/logservice-data/<uuid>/<hostname>`. Docker gives every new container a random hostname, so re-creating the container (`docker compose up --force-recreate`, image upgrades) without a fixed `hostname` fails with `panic: shard not bootstrapped`. Stopping and starting the same container is not affected.
:::

Without persistence, a single command is enough:

```bash
docker run -d -p 6001:6001 --name matrixone matrixorigin/matrixone:4.2.4
```

With persistence, pass both the volume and the hostname:

```bash
docker run -d -p 6001:6001 --name matrixone --hostname matrixone \
  -v "$PWD/mo-data:/mo-data" matrixorigin/matrixone:4.2.4
```

## Standalone with S3 storage

Table data is stored in an S3-compatible bucket (MinIO, AWS S3, Tencent COS, Alibaba OSS...), so the host only needs room for the write-ahead log and a cache. MatrixOne recommends this layout for development and testing, not for production.

The image normally starts `/etc/quickstart/launch.toml`, which keeps everything on local disk. The S3 setup overrides the entrypoint and mounts four configuration files instead:

```
docker/s3/
├── compose.yml
└── etc/
    ├── launch.toml   # lists the three services below
    ├── cn.toml       # compute node
    ├── tn.toml       # transaction node
    └── log.toml      # log service
```

`docker/s3/compose.yml`:

```yaml
services:
  matrixone:
    image: matrixorigin/matrixone:4.2.4
    container_name: matrixone-s3
    hostname: matrixone
    entrypoint: [/mo-service, -debug-http=:12345, -launch, /mo_confs/launch.toml]
    ports:
      - "6001:6001"
    volumes:
      - ./etc:/mo_confs:ro
      - ./mo-data:/mo-data     # WAL and TN state: must persist
      - ./mo-cache:/mo-cache   # disk cache for S3 data
    restart: unless-stopped
```

Each of `cn.toml`, `tn.toml` and `log.toml` declares three file services:

```toml
# Temporary files, one directory per service.
[[fileservice]]
name = "LOCAL"
backend = "DISK"
data-dir = "/mo-data/local/cn"

# Table data on S3.
[[fileservice]]
name = "SHARED"
backend = "MINIO"            # "S3" for AWS S3, COS, OSS...

[fileservice.cache]
memory-capacity = "1GiB"
disk-capacity = "10GiB"
disk-path = "/mo-cache/cn"

[fileservice.s3]
endpoint = "https://minio.example.com"
bucket = "matrixone"
key-prefix = "mo/data"
region = "us-east-1"
key-id = "YOUR_ACCESS_KEY"
key-secret = "YOUR_SECRET_KEY"

# Observability data, same bucket with another prefix.
[[fileservice]]
name = "ETL"
backend = "MINIO"

[fileservice.cache]
memory-capacity = "1B"

[fileservice.s3]
endpoint = "https://minio.example.com"
bucket = "matrixone"
key-prefix = "mo/etl"
region = "us-east-1"
key-id = "YOUR_ACCESS_KEY"
key-secret = "YOUR_SECRET_KEY"
```

1. Create the bucket and an access key.
2. Replace `endpoint`, `bucket`, `region`, `key-id` and `key-secret` in the `[fileservice.s3]` sections of all three service files.
3. Start it:

   ```bash
   cd docker/s3
   docker compose up -d
   docker logs -f matrixone-s3
   ```

4. After creating a table, objects appear under `mo/data/` in the bucket.

::: warning Keep `mo-data`
Only table data goes to S3. The log service write-ahead log and transaction node state stay in `./mo-data`, under a directory named after the container hostname (hence the fixed `hostname`). If that directory is lost, the objects in the bucket can no longer be used. To start over, delete `./mo-data` **and** the `mo/` prefix in the bucket.
:::

::: tip Key names
MatrixOne expects `key-id` and `key-secret` inside `[fileservice.s3]`. Other spellings such as `access-key-id` are silently ignored.
:::

## Adding compute nodes (manual scale-out)

MatrixOne separates storage from compute. The transaction node (TN) and the log service keep the state; compute nodes (CN) parse and run SQL, read table data from shared storage and hold nothing of their own. Scaling out means starting more CNs, which a Kubernetes operator does automatically. The S3 setup can simulate it by hand: a second CN in its own container joins the standalone server, reads the same data from the bucket, and serves SQL on its own port.

```
                 ┌─ matrixone (container) ──────────────────────┐
127.0.0.1:6001 ─▶│  CN 1    TN    log service + HAKeeper :32001 │
                 └──────────────────────▲───────────────────────┘
                                        │  matrixone:32001, :19000-19002
                 ┌─ cn2 (container) ────┴───────────────────────┐
127.0.0.1:6002 ─▶│  CN 2                                        │
                 └──────────────────────────────────────────────┘
                  both CNs read table data from the same S3 bucket
```

This needs the [S3 setup](#standalone-with-s3-storage): a CN can only read data it can reach, and the local-disk standalone keeps its data inside its own container. It is meant for development and experiments (separating an analytics workload, trying read/write splitting, seeing how a CN joins and leaves), not for high availability: all services run on one host, with one TN and one log service.

### How a CN joins

A new CN only needs the HAKeeper address. HAKeeper, which runs inside the log service, tells it where the TN, the other CNs and the lock service are. It passes on the addresses each service advertises, and a standalone server advertises `127.0.0.1` by default, which the CN container cannot reach. So `docker/s3` configures both sides:

- **The main server advertises its compose service name**, `matrixone`. In `etc/tn.toml`, the logtail server needs its own address because it does not follow `service-host`:

  ```toml
  [tn]
  uuid = "dd4dccb4-4d3c-41f8-b482-5251dc7a41bf"
  port-base = 19000
  service-host = "matrixone"

  [tn.LogtailServer]
  listen-address = "0.0.0.0:19001"
  service-address = "matrixone:19001"
  ```

  and in `etc/cn.toml`:

  ```toml
  [cn]
  uuid = "dd1dccb4-4d3c-41f8-b482-5251dc7a41bf"
  port-base = 18000
  service-host = "matrixone"
  ```

  The log service keeps its defaults: its raft address is stored with its data, and CNs only need HAKeeper, which listens on all interfaces. These settings also work without a second CN, and the existing data is kept.

- **The new CN** (`cn2/etc/cn.toml`) is a copy of `etc/cn.toml` with its own uuid, ports and directories, and the HAKeeper address:

  ```toml
  [cn]
  uuid = "dd1dccb4-4d3c-41f8-b482-5251dc7a41c2"   # unique per CN
  port-base = 18100
  service-host = "cn2"                           # how other services reach it
  sql-address = "cn2:6002"

  [cn.frontend]
  port = 6002

  # ... the same LOCAL / SHARED / ETL file services as etc/cn.toml,
  # with data-dir = "/mo-data/local/cn2" and disk-path = "/mo-cache/cn2"

  [hakeeper-client]
  service-addresses = ["matrixone:32001"]
  ```

  Fill in the same `[fileservice.s3]` credentials as the main server.

The CN runs in its own container, off by default through a compose profile:

```yaml
  cn2:
    image: matrixorigin/matrixone:4.2.4
    container_name: matrixone-cn2
    profiles: [scale]
    depends_on: [matrixone]
    entrypoint: [/mo-service, -cfg, /mo_confs/cn.toml]
    ports:
      - "6002:6002"
    volumes:
      - ./cn2/etc:/mo_confs:ro
      - ./cn2/mo-data:/mo-data     # temporary files only
      - ./cn2/mo-cache:/mo-cache
    restart: unless-stopped
```

### Scale out

```bash
cd docker/s3
docker compose restart matrixone          # once, after changing etc/tn.toml and etc/cn.toml
docker compose --profile scale up -d
```

The CN is ready within a few seconds, with no data copied. Check that both CNs are registered:

```sql
show backend servers;
-- dd1dccb4-...-5251dc7a41bf  127.0.0.1:6001  Working
-- dd1dccb4-...-5251dc7a41c2  cn2:6002        Working
```

Both ports serve the same databases. A write on either CN is visible on the other at once, including inside explicit transactions. `show processlist` lists the sessions of every CN; its `host` column tells which CN a session is on.

To add a third CN, copy `cn2/` and its compose service, then change the uuid, `service-host`, `sql-address`, `port-base`, the frontend port and the published port.

### Scale in

```bash
docker compose stop cn2
```

HAKeeper drops the CN from `show backend servers` after about 20 seconds; the other CN keeps serving reads and writes. The first write after that may fail once with `20702 lock table bind changed`, while lock tables held by the stopped CN move to another one. This driver retries that error ([`retry_attempts`](./installation)), and a retried statement succeeds. `docker compose --profile scale up -d` brings the CN back.

### Using the CNs from Laravel

Laravel's read/write connections work unchanged. Reads go to one CN, and writes, and reads after a write when `sticky` is on, go to the other:

```php
'matrixone' => [
    'driver' => 'matrixone',
    'host' => env('MATRIXONE_HOST', '127.0.0.1'),
    'read' => ['port' => 6002],
    'write' => ['port' => 6001],
    'sticky' => true,
    // database, username, password... as usual
],
```

Another option is a separate connection on port 6002, for example for dashboards and reports, so heavy analytical queries do not compete with the application's requests on the same CN.

::: tip Memory
Each CN has its own cache (`[fileservice.cache] memory-capacity`). On a development machine, lower it in every service file (for example `256MiB`) and cap the containers with `mem_limit`. A standalone server then idles at about 1 GiB, and an extra CN at about 250 MiB.
:::

::: warning Not covered here
A CN on another host also needs the log service and the TN reachable over the network, with `service-host` set to host names or IPs and ports 32000-32002, 19000-19002 and 18000-18002 open. A single SQL entry point that balances sessions across CNs needs the MatrixOne proxy (`/etc/launch-with-proxy` in the image, `proxy-enabled = true` on each CN). Neither is part of these files.
:::

## Other deployments

Distributed clusters, Kubernetes, the `mo_ctl` tool, binary installs and MatrixOne Cloud are covered by the official documentation:

- [MatrixOne on GitHub](https://github.com/matrixorigin/matrixone)
- [MatrixOne documentation](https://docs.matrixorigin.cn/mo/en/latest/)
- [Deploy a standalone MatrixOne based on S3](https://docs.matrixorigin.cn/mo/en/latest/MatrixOne/Deploy/deploy-matrixone-single-with-s3.html)
