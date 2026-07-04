<?php

namespace Tests\Unit;

use App\Services\CatteGameEngine;
use PHPUnit\Framework\TestCase;

class CatteGameEngineTest extends TestCase
{
    private CatteGameEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new CatteGameEngine();
    }

    public function test_build_deck_has_52_cards(): void
    {
        $deck = $this->engine->buildDeck();
        $this->assertCount(52, $deck);
        $this->assertCount(52, array_unique($deck));
    }

    public function test_deal_cards_gives_6_per_player(): void
    {
        $hands = $this->engine->dealCards([1, 2, 3, 4]);
        $this->assertCount(4, $hands);
        foreach ($hands as $hand) {
            $this->assertCount(6, $hand);
        }
    }

    public function test_parse_card(): void
    {
        $this->assertEquals(['rank' => 'A', 'suit' => 'H'], $this->engine->parseCard('AH'));
        $this->assertEquals(['rank' => '10', 'suit' => 'D'], $this->engine->parseCard('10D'));
        $this->assertEquals(['rank' => 'K', 'suit' => 'S'], $this->engine->parseCard('KS'));
    }

    public function test_rank_values(): void
    {
        $this->assertEquals(14, $this->engine->getRankValue('A'));
        $this->assertEquals(13, $this->engine->getRankValue('K'));
        $this->assertEquals(2, $this->engine->getRankValue('2'));
    }

    // Instant Win Tests
    public function test_four_of_a_kind_detected(): void
    {
        $hands = [
            1 => ['AH', 'AD', 'AC', 'AS', '7H', '3D'],
            2 => ['2H', '3H', '4H', '5H', '6H', '7D'],
        ];
        $result = $this->engine->checkInstantWin($hands);
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['winner_id']);
        $this->assertEquals('four_of_a_kind', $result['type']);
    }

    public function test_flush_6_detected(): void
    {
        $hands = [
            1 => ['2H', '5H', '7H', '9H', 'JH', 'KH'],
            2 => ['2D', '3S', '4C', '5H', '6D', '7S'],
        ];
        $result = $this->engine->checkInstantWin($hands);
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['winner_id']);
        $this->assertEquals('flush_6', $result['type']);
    }

    public function test_low_6_detected(): void
    {
        $hands = [
            1 => ['2H', '3D', '4C', '5S', '2D', '3H'],
            2 => ['AH', 'KD', 'QC', 'JS', '10H', '9D'],
        ];
        $result = $this->engine->checkInstantWin($hands);
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['winner_id']);
        $this->assertEquals('low_6', $result['type']);
    }

    public function test_four_of_a_kind_beats_flush(): void
    {
        $hands = [
            1 => ['2H', '3H', '4H', '5H', '6H', '7H'], // flush 6
            2 => ['KH', 'KD', 'KC', 'KS', '3D', '4S'], // four of a kind
        ];
        $result = $this->engine->checkInstantWin($hands);
        $this->assertEquals(2, $result['winner_id']);
        $this->assertEquals('four_of_a_kind', $result['type']);
    }

    public function test_no_instant_win(): void
    {
        $hands = [
            1 => ['AH', 'KD', 'QC', 'JS', '10H', '9D'],
            2 => ['2H', '3D', '4C', '5S', '6H', '7D'],
        ];
        $result = $this->engine->checkInstantWin($hands);
        $this->assertNull($result);
    }

    // Validate Play Tests
    public function test_lead_play_must_be_face_up(): void
    {
        $hand = ['AH', 'KD', 'QC'];
        $this->assertTrue($this->engine->validatePlay('AH', false, $hand, null, null, true));
        $this->assertFalse($this->engine->validatePlay('AH', true, $hand, null, null, true));
    }

    public function test_thiep_always_valid(): void
    {
        $hand = ['2S', '3C'];
        $this->assertTrue($this->engine->validatePlay('2S', true, $hand, 'H', 'KH', false));
    }

    public function test_face_up_must_be_same_suit_and_higher(): void
    {
        $hand = ['AH', '5H', '3D'];

        // Same suit, higher rank - valid
        $this->assertTrue($this->engine->validatePlay('AH', false, $hand, 'H', '5H', false));

        // Same suit but lower rank - invalid
        $this->assertFalse($this->engine->validatePlay('5H', false, $hand, 'H', 'KH', false));

        // Different suit - invalid for face up
        $this->assertFalse($this->engine->validatePlay('3D', false, $hand, 'H', '5H', false));
    }

    // Round Evaluation Tests
    public function test_evaluate_round(): void
    {
        $plays = [
            ['player_id' => 1, 'card' => '7H', 'is_face_down' => false],
            ['player_id' => 2, 'card' => 'KH', 'is_face_down' => false],
            ['player_id' => 3, 'card' => '3D', 'is_face_down' => true], // thiệp
            ['player_id' => 4, 'card' => 'AH', 'is_face_down' => false],
        ];
        $winner = $this->engine->evaluateRound($plays, 'H');
        $this->assertEquals(4, $winner); // AH wins
    }

    public function test_evaluate_round_face_down_ignored(): void
    {
        $plays = [
            ['player_id' => 1, 'card' => '7H', 'is_face_down' => false],
            ['player_id' => 2, 'card' => 'AH', 'is_face_down' => true], // thiệp - doesn't win
        ];
        $winner = $this->engine->evaluateRound($plays, 'H');
        $this->assertEquals(1, $winner); // 7H wins because AH is face down
    }

    public function test_chung_round_revealed_face_down_cards_can_win(): void
    {
        $plays = [
            ['player_id' => 1, 'card' => '7H', 'is_face_down' => false],
            ['player_id' => 2, 'card' => 'AH', 'is_face_down' => true],
            ['player_id' => 3, 'card' => 'KD', 'is_face_down' => true],
        ];

        $winner = $this->engine->evaluateRound($plays, 'H', true);

        $this->assertEquals(2, $winner);
    }

    public function test_chung_non_lead_can_submit_any_card_face_down(): void
    {
        $hand = ['3D'];

        $this->assertTrue(
            $this->engine->validatePlay('3D', true, $hand, 'H', '7H', false, true)
        );
    }

    public function test_chung_non_lead_face_up_must_follow_catch_rule(): void
    {
        $hand = ['AH', '3D', '5H'];

        $this->assertTrue(
            $this->engine->validatePlay('AH', false, $hand, 'H', '7H', false, true)
        );

        $this->assertFalse(
            $this->engine->validatePlay('3D', false, $hand, 'H', '7H', false, true)
        );

        $this->assertFalse(
            $this->engine->validatePlay('5H', false, $hand, 'H', '7H', false, true)
        );
    }

    // Post Round 4 Tests
    public function test_guc_tung(): void
    {
        $roundWinners = [1, 2, 1, 2]; // rounds 1-4 winners
        $activePlayers = [1, 2, 3, 4];
        $result = $this->engine->evaluatePostRound4($roundWinners, $activePlayers);

        $this->assertContains(3, $result['eliminated']);
        $this->assertContains(4, $result['eliminated']);
        $this->assertTrue($result['continue']); // 2 survivors
    }

    public function test_thang_tung(): void
    {
        $roundWinners = [1, 1, 1, 1]; // only player 1 won rounds
        $activePlayers = [1, 2, 3, 4];
        $result = $this->engine->evaluatePostRound4($roundWinners, $activePlayers);

        $this->assertEquals(1, $result['winner']);
        $this->assertEquals('thang_tung', $result['win_type']);
        $this->assertFalse($result['continue']);
    }

    // Scoring Tests
    public function test_scoring_normal_win(): void
    {
        $scores = $this->engine->calculateScores(1, 'normal', [3, 4], [], false);
        $this->assertEquals(1, $scores[1]);
        $this->assertEquals(-1, $scores[3]);
        $this->assertEquals(-1, $scores[4]);
    }

    public function test_scoring_thoi_ach(): void
    {
        $hands = [3 => ['AH', 'AD', '5C']]; // 2 aces remaining
        $scores = $this->engine->calculateScores(1, 'thang_tung', [3], $hands, true);
        $this->assertEquals(2, $scores[1]); // thang tung = +2
        $this->assertEquals(-3, $scores[3]); // -1 guc tung + -2 thoi ach (2 aces)
    }

    // Auto-play Tests
    public function test_auto_play_prefers_non_lead_suit(): void
    {
        $hand = ['AH', 'KH', '2D'];
        $result = $this->engine->autoPlay($hand, 'H');
        $this->assertEquals('2D', $result['card']);
        $this->assertTrue($result['face_down']);
    }

    public function test_auto_play_lead_plays_face_up(): void
    {
        $hand = ['AH', 'KD', '3S'];
        $result = $this->engine->autoPlay($hand, null);
        $this->assertFalse($result['face_down']); // lead plays face up
    }
}
