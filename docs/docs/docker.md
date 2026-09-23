# Running MatrixOne with Docker

- [Standalone (local disk)](#standalone-local-disk)
- [Standalone with S3 storage](#standalone-with-s3-storage)
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

## Other deployments

Distributed clusters, Kubernetes, the `mo_ctl` tool, binary installs and MatrixOne Cloud are covered by the official documentation:

- [MatrixOne on GitHub](https://github.com/matrixorigin/matrixone)
- [MatrixOne documentation](https://docs.matrixorigin.cn/mo/en/latest/)
- [Deploy a standalone MatrixOne based on S3](https://docs.matrixorigin.cn/mo/en/latest/MatrixOne/Deploy/deploy-matrixone-single-with-s3.html)
