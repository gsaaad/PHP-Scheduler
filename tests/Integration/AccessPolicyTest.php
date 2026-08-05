<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\AccessPolicy;
use App\Auth\AuthContext;
use App\Exceptions\ForbiddenException;
use App\Models\RobotRepository;

/**
 * The access model is the highest-risk logic in the system: a bug here either
 * leaks another lab's fleet or locks a lab out of its own. These tests pin the
 * semantics -- ANY rule matches, ALL criteria within a rule must hold.
 */
class AccessPolicyTest extends DatabaseTestCase
{
    private function policy(): AccessPolicy
    {
        return new AccessPolicy($this->db);
    }

    private function context(?int $departmentId, bool $isAdmin = false): AuthContext
    {
        return new AuthContext(
            userId: 1,
            username: 'tester',
            departmentId: $departmentId,
            roles: [],
            isAdmin: $isAdmin,
            canSchedule: true,
            canMaintain: true,
            via: 'token',
        );
    }

    private function accessibleIds(AuthContext $auth): array
    {
        $access = $this->policy()->robotFilter($auth);
        $robots = (new RobotRepository($this->db))->getAll(500, 0, $access);

        return array_map(fn ($r) => $r->getId(), $robots);
    }

    public function testAdminReachesEveryRobotWithoutAnyRules(): void
    {
        $robot = $this->insertRobot();

        $this->assertContains($robot, $this->accessibleIds($this->context(null, isAdmin: true)));
    }

    public function testUserWithNoDepartmentReachesNothing(): void
    {
        $this->insertRobot();

        $this->assertSame([], $this->accessibleIds($this->context(null)));
    }

    public function testDepartmentWithNoRulesReachesNothing(): void
    {
        $dept = $this->insertDepartment();
        $this->insertRobot();

        $this->assertSame([], $this->accessibleIds($this->context($dept)));
    }

    /** A rule with zero criteria is an unrestricted grant. */
    public function testRuleWithNoCriteriaGrantsEverything(): void
    {
        $dept  = $this->insertDepartment();
        $robot = $this->insertRobot();
        $this->insertAccessRule($dept, 'Everything', []);

        $this->assertContains($robot, $this->accessibleIds($this->context($dept)));
    }

    public function testArenaCriterionScopesToThatArena(): void
    {
        $dept   = $this->insertDepartment();
        $arenaA = $this->insertArena('LabA');
        $arenaB = $this->insertArena('LabB');

        $inA = $this->insertRobot();
        $inB = $this->insertRobot();
        $this->assignArena($inA, $arenaA);
        $this->assignArena($inB, $arenaB);

        $this->insertAccessRule($dept, 'Lab A only', [['arena', $arenaA, null]]);
        $ids = $this->accessibleIds($this->context($dept));

        $this->assertContains($inA, $ids);
        $this->assertNotContains($inB, $ids);
    }

    /**
     * The "walks AND swims" case: two capability criteria in ONE rule must both
     * hold. A robot with only one of them is out of scope.
     */
    public function testCriteriaWithinARuleAreConjunctive(): void
    {
        $dept = $this->insertDepartment();
        $walk = $this->insertCapability('Walk');
        $swim = $this->insertCapability('Swim');

        $both     = $this->insertRobot();
        $walkOnly = $this->insertRobot();
        $swimOnly = $this->insertRobot();

        $this->grantCapability($both, $walk);
        $this->grantCapability($both, $swim);
        $this->grantCapability($walkOnly, $walk);
        $this->grantCapability($swimOnly, $swim);

        $this->insertAccessRule($dept, 'Amphibious', [
            ['capability', $walk, null],
            ['capability', $swim, null],
        ]);

        $ids = $this->accessibleIds($this->context($dept));

        $this->assertContains($both, $ids);
        $this->assertNotContains($walkOnly, $ids);
        $this->assertNotContains($swimOnly, $ids);
    }

    /** The "swims OR floats" case: separate rules union together. */
    public function testSeparateRulesAreDisjunctive(): void
    {
        $dept  = $this->insertDepartment();
        $swim  = $this->insertCapability('Swim');
        $float = $this->insertCapability('Float');

        $swimmer = $this->insertRobot();
        $floater = $this->insertRobot();
        $neither = $this->insertRobot();

        $this->grantCapability($swimmer, $swim);
        $this->grantCapability($floater, $float);

        $this->insertAccessRule($dept, 'Swimmers', [['capability', $swim, null]]);
        $this->insertAccessRule($dept, 'Floaters', [['capability', $float, null]]);

        $ids = $this->accessibleIds($this->context($dept));

        $this->assertContains($swimmer, $ids);
        $this->assertContains($floater, $ids);
        $this->assertNotContains($neither, $ids);
    }

    public function testDepartmentCriterionScopesToTaggedRobots(): void
    {
        $lab     = $this->insertDepartment('Biology');
        $other   = $this->insertDepartment('Chemistry');
        $bioBot  = $this->insertRobot();
        $chemBot = $this->insertRobot();

        $this->assignDepartment($bioBot, $lab);
        $this->assignDepartment($chemBot, $other);
        $this->insertAccessRule($lab, 'Biology fleet', [['department', $lab, null]]);

        $ids = $this->accessibleIds($this->context($lab));

        $this->assertContains($bioBot, $ids);
        $this->assertNotContains($chemBot, $ids);
    }

    public function testTypeCriterionScopesByRobotType(): void
    {
        $dept     = $this->insertDepartment();
        $research = $this->insertRobot(type: 'research');
        $military = $this->insertRobot(type: 'military');

        $this->insertAccessRule($dept, 'Research hardware', [['type', null, 'research']]);
        $ids = $this->accessibleIds($this->context($dept));

        $this->assertContains($research, $ids);
        $this->assertNotContains($military, $ids);
    }

    public function testMixedKindCriteriaMustAllHold(): void
    {
        $dept  = $this->insertDepartment();
        $arena = $this->insertArena();
        $cap   = $this->insertCapability('Hazmat');

        $match      = $this->insertRobot(type: 'research');
        $wrongType  = $this->insertRobot(type: 'military');
        $noArena    = $this->insertRobot(type: 'research');

        foreach ([$match, $wrongType] as $r) {
            $this->assignArena($r, $arena);
        }
        foreach ([$match, $wrongType, $noArena] as $r) {
            $this->grantCapability($r, $cap);
        }

        $this->insertAccessRule($dept, 'Research hazmat in this lab', [
            ['type', null, 'research'],
            ['arena', $arena, null],
            ['capability', $cap, null],
        ]);

        $ids = $this->accessibleIds($this->context($dept));

        $this->assertContains($match, $ids);
        $this->assertNotContains($wrongType, $ids);
        $this->assertNotContains($noArena, $ids);
    }

    public function testCountRespectsScope(): void
    {
        $dept  = $this->insertDepartment();
        $arena = $this->insertArena();
        $mine  = $this->insertRobot();
        $this->insertRobot(); // someone else's
        $this->assignArena($mine, $arena);
        $this->insertAccessRule($dept, 'Mine', [['arena', $arena, null]]);

        $this->assertSame(1, $this->policy()->accessibleRobotCount($this->context($dept)));
    }

    public function testAssertThrowsForOutOfScopeRobot(): void
    {
        $dept  = $this->insertDepartment();
        $other = $this->insertRobot();
        $this->insertAccessRule($dept, 'Empty scope', [['type', null, 'nonexistent-type']]);

        $this->expectException(ForbiddenException::class);
        $this->policy()->assertCanAccessRobot($this->context($dept), $other);
    }

    /** An out-of-scope robot must be indistinguishable from a missing one. */
    public function testFindReturnsNullForOutOfScopeRobot(): void
    {
        $dept  = $this->insertDepartment();
        $robot = $this->insertRobot();
        $this->insertAccessRule($dept, 'Empty scope', [['type', null, 'nonexistent-type']]);

        $access = $this->policy()->robotFilter($this->context($dept));

        $this->assertNull((new RobotRepository($this->db))->find($robot, $access));
    }

    public function testArenaViewFilterNarrowsButCannotWiden(): void
    {
        $dept   = $this->insertDepartment();
        $mine   = $this->insertArena('Mine');
        $theirs = $this->insertArena('Theirs');

        $inScope    = $this->insertRobot();
        $outOfScope = $this->insertRobot();
        $this->assignArena($inScope, $mine);
        $this->assignArena($outOfScope, $theirs);

        $this->insertAccessRule($dept, 'My arena', [['arena', $mine, null]]);

        $access = $this->policy()->robotFilter($this->context($dept));
        $repo   = new RobotRepository($this->db);

        // Asking for the arena we cannot reach yields nothing, not a bypass.
        $ids = array_map(
            fn ($r) => $r->getId(),
            $repo->getAll(500, 0, $access, ['arena_id' => $theirs])
        );

        $this->assertNotContains($outOfScope, $ids);
        $this->assertSame(0, $repo->count($access, ['arena_id' => $theirs]));
    }
}
