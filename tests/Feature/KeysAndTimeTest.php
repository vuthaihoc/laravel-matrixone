<?php

namespace MatrixOne\Tests\Feature;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class KtOrder extends Model
{
    use HasUuids;

    protected $table = 'kt_orders';

    protected $guarded = [];

    /**
     * @return HasMany<KtLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(KtLine::class, 'order_id');
    }
}

class KtLine extends Model
{
    use HasUlids;

    protected $table = 'kt_lines';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<KtOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(KtOrder::class, 'order_id');
    }
}

class KtEvent extends Model
{
    protected $table = 'kt_events';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['happened_at' => 'datetime', 'on_day' => 'date'];
    }
}

/**
 * UUID/ULID primary keys, time zones and fractional-second timestamps.
 */
class KeysAndTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->tearDownTables();

        Schema::create('kt_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number');
            $table->timestamps();
        });

        Schema::create('kt_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUuid('order_id')->constrained('kt_orders')->cascadeOnDelete();
            $table->string('sku');
        });

        Schema::create('kt_events', function (Blueprint $table) {
            $table->id();
            $table->dateTime('happened_at', 6)->nullable();
            $table->date('on_day')->nullable();
            $table->time('at_time', 3)->nullable();
            $table->timestamp('logged_at', 6)->useCurrent();
            $table->timestamps(6);
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTables();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tearDownTables(): void
    {
        Schema::dropIfExists('kt_lines');
        Schema::dropIfExists('kt_orders');
        Schema::dropIfExists('kt_events');
    }

    public function testUuidAndUlidPrimaryKeysWithRelations(): void
    {
        $order = KtOrder::create(['number' => 'A-1']);

        $this->assertTrue(Str::isUuid($order->id));
        $this->assertSame('A-1', KtOrder::find($order->id)->number);

        $line = $order->lines()->create(['sku' => 'x']);
        $order->lines()->create(['sku' => 'y']);

        $this->assertTrue(Str::isUlid($line->id));
        $this->assertSame($order->id, KtLine::find($line->id)->order->id);

        $loaded = KtOrder::with('lines')->withCount('lines')->get();
        $this->assertSame(2, $loaded[0]->lines_count);
        $this->assertEqualsCanonicalizing(['x', 'y'], $loaded[0]->lines->pluck('sku')->all());

        $this->assertSame($order->id, KtOrder::createOrFirst(['id' => $order->id], ['number' => 'other'])->id);
        $this->assertSame(1, KtOrder::count());

        // Ordered UUIDs sort by creation time.
        $second = KtOrder::create(['number' => 'A-2']);
        $this->assertSame([$order->id, $second->id], KtOrder::orderBy('id')->pluck('id')->all());

        $order->delete();
        $this->assertSame(0, KtLine::count());
    }

    public function testFractionalSecondsRoundTrip(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-04 05:06:07.123456', 'UTC'));

        $event = KtEvent::create([
            'happened_at' => Carbon::parse('2026-01-02 03:04:05.654321', 'UTC'),
            'on_day' => '2026-01-02',
            'at_time' => '12:34:56.789',
        ]);

        $fresh = KtEvent::find($event->id);

        $this->assertSame('2026-01-02 03:04:05.654321', $fresh->happened_at->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-03-04 05:06:07.123456', $fresh->created_at->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-01-02', $fresh->on_day->toDateString());
        $this->assertSame('12:34:56.789', $fresh->at_time);

        // Microsecond comparisons work with string bindings. As with Laravel's
        // MySQL driver, Carbon bindings are formatted without fractions.
        $this->assertSame(1, KtEvent::where('happened_at', '>', '2026-01-02 03:04:05.654320')->count());
        $this->assertSame(0, KtEvent::where('happened_at', '>', '2026-01-02 03:04:05.654321')->count());
        $this->assertSame(1, KtEvent::where('happened_at', '>', Carbon::parse('2026-01-02 03:04:05.999999', 'UTC'))->count());
        $this->assertSame(1, KtEvent::whereDate('happened_at', '2026-01-02')->whereTime('happened_at', '>=', '03:04:05')->count());
    }

    public function testTimestampColumnsFollowTheSessionTimeZone(): void
    {
        config(['database.connections.mo_hcm' => array_merge(config('database.connections.matrixone'), ['timezone' => '+07:00'])]);
        config(['database.connections.mo_utc' => array_merge(config('database.connections.matrixone'), ['timezone' => '+00:00'])]);

        DB::connection('mo_hcm')->table('kt_events')->insert([
            'happened_at' => '2026-01-01 00:00:00',
            'logged_at' => '2026-01-01 00:00:00',
        ]);

        // MatrixOne may omit a zero fraction (".000000") where MySQL pads it,
        // so values are compared as times.
        $time = fn ($value) => Carbon::parse((string) $value)->format('Y-m-d H:i:s.u');

        // Same session time zone: values come back as written.
        $local = DB::connection('mo_hcm')->table('kt_events')->first();
        $this->assertSame('2026-01-01 00:00:00.000000', $time($local->logged_at));

        // timestamp is stored in UTC and converted per session, datetime is not.
        $utc = DB::connection('mo_utc')->table('kt_events')->first();
        $this->assertSame('2025-12-31 17:00:00.000000', $time($utc->logged_at));
        $this->assertSame('2026-01-01 00:00:00.000000', $time($utc->happened_at));
    }

    public function testCurrentTimestampDefaultsMatchTheApplicationClock(): void
    {
        config(['database.connections.mo_utc' => array_merge(config('database.connections.matrixone'), ['timezone' => '+00:00'])]);

        DB::connection('mo_utc')->table('kt_events')->insert(['on_day' => '2026-01-01']);

        $logged = Carbon::parse((string) DB::connection('mo_utc')->table('kt_events')->value('logged_at'), 'UTC');

        $this->assertLessThan(120, abs($logged->diffInSeconds(Carbon::now('UTC'))));
    }
}
