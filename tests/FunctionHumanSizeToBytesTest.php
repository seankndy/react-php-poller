<?php

namespace SeanKndy\Poller\Tests;

use function SeanKndy\Poller\humanSizeToBytes;

class FunctionHumanSizeToBytesTest extends TestCase
{
    /** @test */
    public function it_assumes_bytes_when_no_unit_provided(): void
    {
        $this->assertEquals(100, humanSizeToBytes(100));
        $this->assertEquals(100, humanSizeToBytes('100'));
    }

    /** @test */
    public function it_assumes_bytes_when_unknown_unit_provided(): void
    {
        $this->assertEquals(100, humanSizeToBytes('100 WhoKnows'));
    }

    /** @test */
    public function it_returns_zero_when_empty_input_provided(): void
    {
        $this->assertEquals(0, humanSizeToBytes(''));
    }

    /** @test */
    public function it_returns_bytes_when_bytes_unit_provided(): void
    {
        $this->assertEquals(100, humanSizeToBytes('100 B'));
        $this->assertEquals(100, humanSizeToBytes('100B'));
        $this->assertEquals(100, humanSizeToBytes('100Bytes'));
        $this->assertEquals(100, humanSizeToBytes('100 Bytes'));
    }

    /** @test */
    public function it_converts_kilobytes_to_bytes(): void
    {
        $this->assertEquals(1000000, humanSizeToBytes('1000 KB'));
        $this->assertEquals(1000000, humanSizeToBytes('1000KB'));
        $this->assertEquals(1000000, humanSizeToBytes('1000KiloBytes'));
        $this->assertEquals(1000000, humanSizeToBytes('1000 kilobytes'));
        $this->assertEquals(1000500, humanSizeToBytes('1000.5 Kilobytes'));
    }

    /** @test */
    public function it_converts_megabytes_to_bytes(): void
    {
        $this->assertEquals(10000000, humanSizeToBytes('10 MB'));
        $this->assertEquals(10000000, humanSizeToBytes('10MB'));
        $this->assertEquals(10000000, humanSizeToBytes('10MegaBytes'));
        $this->assertEquals(10000000, humanSizeToBytes('10 Megabytes'));
        $this->assertEquals(10000000, humanSizeToBytes('10 megabytes'));
        $this->assertEquals(10300000, humanSizeToBytes('10.3 Megabytes'));
    }

    /** @test */
    public function it_converts_gigabytes_to_bytes(): void
    {
        $this->assertEquals(1000000000, humanSizeToBytes('1 GB'));
        $this->assertEquals(1000000000, humanSizeToBytes('1GB'));
        $this->assertEquals(1000000000, humanSizeToBytes('1GigaBytes'));
        $this->assertEquals(1000000000, humanSizeToBytes('1 Gigabytes'));
        $this->assertEquals(1000000000, humanSizeToBytes('1 gigabytes'));
        $this->assertEquals(1200000000, humanSizeToBytes('1.2 Gigabytes'));
    }

    /** @test */
    public function it_converts_terabytes_to_bytes(): void
    {
        $this->assertEquals(1000000000000, humanSizeToBytes('1 TB'));
        $this->assertEquals(1000000000000, humanSizeToBytes('1TB'));
        $this->assertEquals(1000000000000, humanSizeToBytes('1TeraBytes'));
        $this->assertEquals(1000000000000, humanSizeToBytes('1 Terabytes'));
        $this->assertEquals(1000000000000, humanSizeToBytes('1 terabytes'));
        $this->assertEquals(1100000000000, humanSizeToBytes('1.1 Terabytes'));
    }

    /** @test */
    public function it_converts_bits_to_bytes(): void
    {
        $this->assertEquals(12500, humanSizeToBytes('100000 b'));
        $this->assertEquals(12500, humanSizeToBytes('100000Bits'));
        $this->assertEquals(12500, humanSizeToBytes('100000 bits'));
        $this->assertEquals(12500, humanSizeToBytes('100000 Bits'));
    }

    /** @test */
    public function it_converts_kilobits_to_bytes(): void
    {
        $this->assertEquals(125000, humanSizeToBytes('1000 kb'));
        $this->assertEquals(125000, humanSizeToBytes('1000 Kb'));
        $this->assertEquals(125000, humanSizeToBytes('1000 kilobits'));
        $this->assertEquals(125000, humanSizeToBytes('1000KiloBits'));
        $this->assertEquals(125000, humanSizeToBytes('1000 Kilobits'));
        $this->assertEquals(125000, humanSizeToBytes('1000 Kbit'));
        $this->assertEquals(125100, humanSizeToBytes('1000.8 Kbit'));
    }

    /** @test */
    public function it_converts_megabits_to_bytes(): void
    {
        $this->assertEquals(12500000, humanSizeToBytes('100 mb'));
        $this->assertEquals(12500000, humanSizeToBytes('100 Mb'));
        $this->assertEquals(12500000, humanSizeToBytes('100 megabits'));
        $this->assertEquals(12500000, humanSizeToBytes('100MegaBits'));
        $this->assertEquals(12500000, humanSizeToBytes('100 Megabits'));
        $this->assertEquals(12500000, humanSizeToBytes('100 Mbit'));
        $this->assertEquals(12600000, humanSizeToBytes('100.8 Mbit'));
    }

    /** @test */
    public function it_converts_gigabits_to_bytes(): void
    {
        $this->assertEquals(1250000000, humanSizeToBytes('10 gb'));
        $this->assertEquals(1250000000, humanSizeToBytes('10 Gb'));
        $this->assertEquals(1250000000, humanSizeToBytes('10 gigabits'));
        $this->assertEquals(1250000000, humanSizeToBytes('10GigaBits'));
        $this->assertEquals(1250000000, humanSizeToBytes('10 Gigabits'));
        $this->assertEquals(1250000000, humanSizeToBytes('10 Gbit'));
        $this->assertEquals(1300000000, humanSizeToBytes('10.4 Gbit'));
    }

    /** @test */
    public function it_converts_terabits_to_bytes(): void
    {
        $this->assertEquals(125000000000, humanSizeToBytes('1 tb'));
        $this->assertEquals(125000000000, humanSizeToBytes('1 Tb'));
        $this->assertEquals(125000000000, humanSizeToBytes('1 terabits'));
        $this->assertEquals(125000000000, humanSizeToBytes('1TeraBits'));
        $this->assertEquals(125000000000, humanSizeToBytes('1 Terabits'));
        $this->assertEquals(125000000000, humanSizeToBytes('1 Tbit'));
        $this->assertEquals(225000000000, humanSizeToBytes('1.8 Tbit'));
    }
}