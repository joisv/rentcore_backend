<?php

namespace Tests\Unit;

use App\Services\QrisService;
use Tests\TestCase;

class QrisServiceTest extends TestCase
{
    protected QrisService $qrisService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qrisService = new QrisService();
    }

    public function test_calculate_crc16_standard_ccitt_false_vector(): void
    {
        // Standard EMVCo/CCITT-FALSE vector for '123456789' is 0x29B1
        $crc = $this->qrisService->calculateCrc16('123456789');
        $this->assertEquals('29B1', $crc);
    }

    public function test_is_valid_qris_payload(): void
    {
        $valid = '00020101021126580014ID.LINKAJA.WWW0118936009180000000001020712345675204581253033605802ID5911RENTCORE POS6007JAKARTA6105123406304A1B2';
        $this->assertTrue($this->qrisService->isValidQrisPayload($valid));

        // Invalid: missing 000201 or 5802ID
        $this->assertFalse($this->qrisService->isValidQrisPayload('Hello world!'));
        $this->assertFalse($this->qrisService->isValidQrisPayload('000201some_other_qr_code'));
        $this->assertFalse($this->qrisService->isValidQrisPayload(null));
        $this->assertFalse($this->qrisService->isValidQrisPayload(''));
    }

    public function test_decode_valid_qris_fixture(): void
    {
        $fixturePath = base_path('tests/Fixtures/sample_qris.png');
        $this->assertFileExists($fixturePath);

        $decoded = $this->qrisService->decodeQrisImage($fixturePath);
        $this->assertNotNull($decoded);
        $this->assertStringStartsWith('000201', $decoded);
        $this->assertStringContainsString('5802ID', $decoded);
    }

    public function test_generate_dynamic_qris_converts_tag01_injects_tag54_and_recalculates_crc(): void
    {
        $staticPayload = '00020101021126580014ID.LINKAJA.WWW0118936009180000000001020712345675204581253033605802ID5911RENTCORE POS6007JAKARTA6105123406304A1B2';

        $dynamicPayload = $this->qrisService->generateDynamicQris($staticPayload, 50000);

        // Tag 01 must be dynamic (010212)
        $this->assertStringContainsString('010212', $dynamicPayload);
        $this->assertStringNotContainsString('010211', $dynamicPayload);

        // Tag 54 must be injected (540550000)
        $this->assertStringContainsString('540550000', $dynamicPayload);

        // Checksum at the end must be valid CRC-16
        $expectedPayloadUpToCrc = substr($dynamicPayload, 0, -4);
        $checksum = substr($dynamicPayload, -4);
        $this->assertEquals($this->qrisService->calculateCrc16($expectedPayloadUpToCrc), $checksum);
    }

    public function test_generate_dynamic_qris_replaces_existing_tag54_reactively(): void
    {
        $staticPayload = '00020101021126580014ID.LINKAJA.WWW0118936009180000000001020712345675204581253033605802ID5911RENTCORE POS6007JAKARTA6105123406304A1B2';

        $firstDynamic = $this->qrisService->generateDynamicQris($staticPayload, 50000);
        $this->assertStringContainsString('540550000', $firstDynamic);

        // Re-inject higher amount (e.g. extension fee of 120,000)
        $updatedDynamic = $this->qrisService->generateDynamicQris($firstDynamic, 120000);
        $this->assertStringContainsString('5406120000', $updatedDynamic);
        $this->assertStringNotContainsString('540550000', $updatedDynamic);

        // CRC remains valid
        $expectedPayloadUpToCrc = substr($updatedDynamic, 0, -4);
        $checksum = substr($updatedDynamic, -4);
        $this->assertEquals($this->qrisService->calculateCrc16($expectedPayloadUpToCrc), $checksum);
    }
}
