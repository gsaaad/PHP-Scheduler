<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Http\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    public function testAcceptsAValidRobotAndNormalisesIt(): void
    {
        $result = Validator::robot(['name' => '  Scout-01 ', 'type' => 'WAREHOUSE', 'battery_level' => '80']);

        $this->assertSame(['name' => 'Scout-01', 'type' => 'warehouse', 'battery_level' => 80], $result);
    }

    public function testRobotBatteryDefaultsTo100(): void
    {
        $this->assertSame(100, Validator::robot(['name' => 'x', 'type' => 'research'])['battery_level']);
    }

    /**
     * A null body is what json_decode() returns for a malformed or empty
     * payload. It used to reach PDO and become a 500.
     */
    #[DataProvider('nonArrayBodyProvider')]
    public function testRejectsNonObjectBody(mixed $body): void
    {
        $this->expectException(ValidationException::class);
        Validator::robot($body);
    }

    public static function nonArrayBodyProvider(): array
    {
        // Keys name the dataset; the inner array holds the arguments.
        return [
            'null body'    => [null],
            'string body'  => ['not json'],
            'numeric body' => [5],
            'bool body'    => [true],
        ];
    }

    /** Out-of-range values used to trip the CHECK constraint as an uncaught PDOException. */
    #[DataProvider('badBatteryProvider')]
    public function testRejectsOutOfRangeBattery(mixed $battery): void
    {
        try {
            Validator::robot(['name' => 'x', 'type' => 'research', 'battery_level' => $battery]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('battery_level', $e->getErrors());
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public static function badBatteryProvider(): array
    {
        // null is deliberately absent: `?? 100` treats it as "field omitted",
        // which is a legitimate default rather than an error.
        return [
            'above range'  => [500],
            'negative'     => [-1],
            'just over'    => [101],
            'non-numeric'  => ['abc'],
            'fractional'   => [1.5],
            'array'        => [[]],
            'boolean'      => [true],
        ];
    }

    public function testRejectsUnknownRobotType(): void
    {
        try {
            Validator::robot(['name' => 'x', 'type' => 'submarine']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->getErrors());
        }
    }

    public function testCollectsEveryFieldErrorAtOnce(): void
    {
        try {
            Validator::robot(['name' => '', 'type' => '', 'battery_level' => 900]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['name', 'type', 'battery_level'], array_keys($e->getErrors()));
        }
    }

    public function testRejectsOverlongName(): void
    {
        $this->expectException(ValidationException::class);
        Validator::robot(['name' => str_repeat('a', 101), 'type' => 'research']);
    }

    /**
     * Lengths are counted in characters, matching VARCHAR(100) in Postgres.
     * A byte count would wrongly reject this 60-character name (180 bytes), and
     * mb_strlen is not available on every build -- the validator falls back to
     * a PCRE count rather than going fatal.
     */
    public function testNameLengthIsCountedInCharactersNotBytes(): void
    {
        $name = str_repeat('ロボット工', 12); // 60 characters, 180 bytes in UTF-8

        $this->assertSame($name, Validator::robot(['name' => $name, 'type' => 'research'])['name']);
    }

    public function testRejectsOverlongMultibyteName(): void
    {
        $this->expectException(ValidationException::class);
        Validator::robot(['name' => str_repeat('ロ', 101), 'type' => 'research']);
    }

    public function testValidatesTaskAndAcceptsDurationAlias(): void
    {
        $result = Validator::task(['title' => 'Move Pallet', 'duration' => 45, 'priority' => 3]);

        $this->assertSame(45, $result['estimated_duration']);
        $this->assertSame(3, $result['priority']);
        $this->assertNull($result['description']);
    }

    public function testTaskDefaults(): void
    {
        $result = Validator::task(['title' => 'Data Sync']);

        $this->assertSame(1, $result['priority']);
        $this->assertSame(30, $result['estimated_duration']);
    }

    public function testValidatesScheduleIntoADateTime(): void
    {
        $result = Validator::schedule([
            'robot_id'   => '4',
            'task_id'    => 2,
            'start_time' => '2026-08-04 09:00:00',
        ]);

        $this->assertSame(4, $result['robot_id']);
        $this->assertSame('2026-08-04 09:00:00', $result['start_time']->format('Y-m-d H:i:s'));
    }

    public function testRejectsUnparseableStartTime(): void
    {
        try {
            Validator::schedule(['robot_id' => 1, 'task_id' => 1, 'start_time' => 'whenever']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start_time', $e->getErrors());
        }
    }

    public function testRejectsNonPositiveIds(): void
    {
        try {
            Validator::schedule(['robot_id' => 0, 'task_id' => -3, 'start_time' => '2026-08-04']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('robot_id', $e->getErrors());
            $this->assertArrayHasKey('task_id', $e->getErrors());
        }
    }

    public function testPaginationClampsAndFallsBackSafely(): void
    {
        $this->assertSame(['limit' => 50, 'offset' => 0], Validator::pagination([]));
        $this->assertSame(['limit' => 200, 'offset' => 0], Validator::pagination(['limit' => 99999]));
        $this->assertSame(['limit' => 1, 'offset' => 0], Validator::pagination(['limit' => 0]));
        // Junk falls back to the default instead of throwing -- query strings
        // are not worth a 422.
        $this->assertSame(['limit' => 50, 'offset' => 0], Validator::pagination(['limit' => 'abc', 'offset' => '-5']));
        $this->assertSame(['limit' => 10, 'offset' => 20], Validator::pagination(['limit' => '10', 'offset' => '20']));
    }

    public function testRobotStatusMustBeAKnownValue(): void
    {
        $this->assertSame(['status' => 'charging'], Validator::robotStatus(['status' => 'CHARGING']));

        $this->expectException(ValidationException::class);
        Validator::robotStatus(['status' => 'exploded']);
    }
}
