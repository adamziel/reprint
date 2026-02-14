<?php

declare(strict_types=1);

require_once __DIR__ . '/../importer/import.php';

use PHPUnit\Framework\TestCase;

class AdaptiveTunerTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        $tuner = new AdaptiveTuner([]);
        $config = $tuner->get_config();
        $state = $tuner->get_state();

        $this->assertTrue($config['enabled']);
        $this->assertSame(5, $config['max_execution_time']);
        $this->assertSame(5 * 1024 * 1024, $state['file_chunk_size']);
        $this->assertSame(5000, $state['index_batch_size']);
        $this->assertSame(1000, $state['sql_fragments_per_batch']);
        $this->assertNull($state['file_throughput_ema']);
        $this->assertSame(0, $state['error_backoff_remaining']);
    }

    public function testConstructionWithCustomConfig(): void
    {
        $tuner = new AdaptiveTuner([
            'file_chunk_start' => 1024,
            'file_chunk_min' => 512,
            'file_chunk_max' => 2048,
            'duty' => 0.8,
        ]);
        $config = $tuner->get_config();
        $state = $tuner->get_state();

        $this->assertSame(1024, $state['file_chunk_size']);
        $this->assertSame(512, $config['file_chunk_min']);
        $this->assertSame(2048, $config['file_chunk_max']);
        $this->assertEqualsWithDelta(0.8, $state['duty'], 0.001);
    }

    public function testGetRequestParamsFileFetch(): void
    {
        $tuner = new AdaptiveTuner(['file_chunk_start' => 1000000]);
        $params = $tuner->get_request_params('file_fetch');

        $this->assertSame(1000000, $params['chunk_size']);
        $this->assertSame(5, $params['max_execution_time']);
        $this->assertArrayNotHasKey('batch_size', $params);
    }

    public function testGetRequestParamsFileIndex(): void
    {
        $tuner = new AdaptiveTuner(['index_batch_start' => 2000]);
        $params = $tuner->get_request_params('file_index');

        $this->assertSame(2000, $params['batch_size']);
        $this->assertArrayNotHasKey('chunk_size', $params);
    }

    public function testGetRequestParamsSqlChunk(): void
    {
        $tuner = new AdaptiveTuner([
            'sql_fragments_start' => 500,
            'db_unbuffered' => true,
            'db_query_time_limit' => 1000,
        ]);
        $params = $tuner->get_request_params('sql_chunk');

        $this->assertSame(500, $params['fragments_per_batch']);
        $this->assertSame(1, $params['db_unbuffered']);
        $this->assertSame(1000, $params['db_query_time_limit']);
    }

    public function testAimdIncreaseOnSteadyThroughput(): void
    {
        $tuner = new AdaptiveTuner([
            'file_chunk_start' => 1000,
            'file_chunk_min' => 100,
            'file_chunk_max' => 100000,
            'aimd_increase_file_bytes' => 500,
            'use_server_time' => true,
        ]);

        // First call: warmup (seeds EMA).
        $result = $tuner->record_result('file_fetch', [
            'server_time' => 1.0,
            'wall_time' => 1.0,
            'status' => 'partial',
            'bytes_processed' => 1000,
        ]);
        $this->assertSame('warmup', $result['decision']);

        // Second call: steady throughput → increase.
        $result = $tuner->record_result('file_fetch', [
            'server_time' => 1.0,
            'wall_time' => 1.0,
            'status' => 'partial',
            'bytes_processed' => 1000,
        ]);
        $this->assertSame('increase', $result['decision']);
        $this->assertSame(1500, $tuner->get_state()['file_chunk_size']);
    }

    public function testAimdDecreaseOnThroughputDrop(): void
    {
        $tuner = new AdaptiveTuner([
            'file_chunk_start' => 10000,
            'file_chunk_min' => 100,
            'file_chunk_max' => 100000,
            'aimd_decrease_factor' => 0.5,
            'aimd_drop_ratio' => 0.9,
            'use_server_time' => true,
        ]);

        // Warmup with high throughput.
        $tuner->record_result('file_fetch', [
            'server_time' => 1.0,
            'wall_time' => 1.0,
            'status' => 'partial',
            'bytes_processed' => 10000,
        ]);

        // Big throughput drop → decrease.
        $result = $tuner->record_result('file_fetch', [
            'server_time' => 1.0,
            'wall_time' => 1.0,
            'status' => 'partial',
            'bytes_processed' => 1000,
        ]);
        $this->assertSame('decrease', $result['decision']);
        // 10000 * 0.5 = 5000
        $this->assertSame(5000, $tuner->get_state()['file_chunk_size']);
    }

    public function testErrorBackoffShrinksAndHolds(): void
    {
        $tuner = new AdaptiveTuner([
            'file_chunk_start' => 10000,
            'file_chunk_min' => 100,
            'file_chunk_max' => 100000,
            'error_decrease_factor' => 0.5,
            'error_backoff_requests' => 2,
            'use_server_time' => true,
        ]);

        // Error shrinks size and sets backoff counter.
        $result = $tuner->record_error('file_fetch', [
            'http_code' => 500,
            'timeout' => false,
        ]);
        $this->assertSame('backoff', $result['decision']);
        $this->assertSame(5000, $tuner->get_state()['file_chunk_size']);
        $this->assertSame(2, $tuner->get_state()['error_backoff_remaining']);

        // Next record_result: held steady during backoff (counter decrements).
        $result = $tuner->record_result('file_fetch', [
            'server_time' => 1.0,
            'wall_time' => 1.0,
            'status' => 'partial',
            'bytes_processed' => 5000,
        ]);
        $this->assertSame('error_backoff', $result['decision']);
        $this->assertSame(1, $tuner->get_state()['error_backoff_remaining']);

        // Second request during backoff.
        $result = $tuner->record_result('file_fetch', [
            'server_time' => 1.0,
            'wall_time' => 1.0,
            'status' => 'partial',
            'bytes_processed' => 5000,
        ]);
        // Still error_backoff because counter was 1 at entry, decremented after.
        $this->assertSame('error_backoff', $result['decision']);
        $this->assertSame(0, $tuner->get_state()['error_backoff_remaining']);

        // Third request: backoff expired, normal tuning resumes (warmup since EMA was seeded during backoff).
        $result = $tuner->record_result('file_fetch', [
            'server_time' => 1.0,
            'wall_time' => 1.0,
            'status' => 'partial',
            'bytes_processed' => 5000,
        ]);
        $this->assertNotSame('error_backoff', $result['decision']);
    }

    public function testDutyCycleSleepComputation(): void
    {
        $tuner = new AdaptiveTuner([
            'duty' => 0.5,
            'duty_min' => 0.5,
            'duty_max' => 0.5,
            'min_sleep' => 0.0,
            'max_sleep' => 100.0,
            'use_server_time' => true,
        ]);

        $result = $tuner->record_result('file_fetch', [
            'server_time' => 2.0,
            'wall_time' => 2.0,
            'status' => 'partial',
            'bytes_processed' => 1000,
        ]);

        // duty=0.5 → sleep = elapsed * (1/0.5 - 1) = 2.0 * 1.0 = 2.0
        $this->assertEqualsWithDelta(2.0, $result['sleep_seconds'], 0.01);
    }

    public function testDisabledTunerReturnsZeroSleep(): void
    {
        $tuner = new AdaptiveTuner(['enabled' => false]);

        $result = $tuner->record_result('file_fetch', [
            'server_time' => 2.0,
            'wall_time' => 2.0,
            'status' => 'partial',
            'bytes_processed' => 1000,
        ]);

        $this->assertSame('disabled', $result['decision']);
        $this->assertSame(0.0, $result['sleep_seconds']);
    }

    public function testStatePersistenceRoundTrip(): void
    {
        $tuner = new AdaptiveTuner([
            'file_chunk_start' => 1000,
            'file_chunk_min' => 100,
            'file_chunk_max' => 100000,
            'use_server_time' => true,
        ]);

        // Do some work to mutate state.
        $tuner->record_result('file_fetch', [
            'server_time' => 1.0,
            'wall_time' => 1.0,
            'status' => 'partial',
            'bytes_processed' => 1000,
        ]);

        $config = $tuner->get_config();
        $state = $tuner->get_state();

        // Reconstruct from persisted config+state.
        $tuner2 = new AdaptiveTuner($config, $state);
        $this->assertSame($state, $tuner2->get_state());
    }

    public function testErrorIgnoredForNonErrorStatusCodes(): void
    {
        $tuner = new AdaptiveTuner([]);

        $result = $tuner->record_error('file_fetch', [
            'http_code' => 200,
            'timeout' => false,
        ]);

        $this->assertSame('ignore', $result['decision']);
        $this->assertSame(0, $tuner->get_state()['error_backoff_remaining']);
    }

    public function testNoSleepOnCompleteStatus(): void
    {
        $tuner = new AdaptiveTuner([
            'duty' => 0.5,
            'use_server_time' => true,
        ]);

        $result = $tuner->record_result('file_fetch', [
            'server_time' => 2.0,
            'wall_time' => 2.0,
            'status' => 'complete',
            'bytes_processed' => 1000,
        ]);

        $this->assertSame(0.0, $result['sleep_seconds']);
    }

    public function testUnknownEndpointReturnsBaseParams(): void
    {
        $tuner = new AdaptiveTuner([]);
        $params = $tuner->get_request_params('unknown_endpoint');

        $this->assertArrayHasKey('max_execution_time', $params);
        $this->assertArrayHasKey('memory_threshold', $params);
        $this->assertCount(2, $params);
    }
}
