<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Play;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\Score;

class CatteGameEngine
{
    // Rank order: A(14) > K(13) > Q(12) > J(11) > 10 > 9 > 8 > 7 > 6 > 5 > 4 > 3 > 2
    public const RANK_VALUES = [
        '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6,
        '7' => 7, '8' => 8, '9' => 9, '10' => 10,
        'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14,
    ];

    // Suit order for instant win tie-break only: H > D > C > S
    public const SUIT_VALUES = ['S' => 1, 'C' => 2, 'D' => 3, 'H' => 4];

    public const SUITS = ['H', 'D', 'C', 'S'];
    public const RANKS = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];

    /**
     * Build a standard 52-card deck.
     */
    public function buildDeck(): array
    {
        $deck = [];
        foreach (self::SUITS as $suit) {
            foreach (self::RANKS as $rank) {
                $deck[] = $rank . $suit;
            }
        }
        return $deck;
    }

    /**
     * Shuffle and deal cards to players.
     * @return array {player_id: [card1, card2, ...]}
     */
    public function dealCards(array $playerIds): array
    {
        $deck = $this->buildDeck();
        shuffle($deck);

        $hands = [];
        $cardsPerPlayer = 6;

        foreach ($playerIds as $i => $playerId) {
            $hands[$playerId] = array_slice($deck, $i * $cardsPerPlayer, $cardsPerPlayer);
        }

        return $hands;
    }

    /**
     * Parse card into rank and suit.
     */
    public function parseCard(string $card): array
    {
        $suit = substr($card, -1);
        $rank = substr($card, 0, -1);
        return ['rank' => $rank, 'suit' => $suit];
    }

    /**
     * Get numeric rank value.
     */
    public function getRankValue(string $rank): int
    {
        return self::RANK_VALUES[$rank] ?? 0;
    }

    /**
     * Get numeric suit value (for instant win tie-break only).
     */
    public function getSuitValue(string $suit): int
    {
        return self::SUIT_VALUES[$suit] ?? 0;
    }

    // =========================================================================
    // INSTANT WIN CHECK
    // =========================================================================

    /**
     * Check all players for instant win conditions.
     * Returns: ['winner_id' => int, 'type' => string] or null
     */
    public function checkInstantWin(array $hands): ?array
    {
        $candidates = [];

        foreach ($hands as $playerId => $cards) {
            $result = $this->checkPlayerInstantWin($cards);
            if ($result) {
                $result['player_id'] = $playerId;
                $candidates[] = $result;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Sort by precedence: four_of_a_kind > flush_6 > low_6
        usort($candidates, function ($a, $b) {
            $typeOrder = ['four_of_a_kind' => 3, 'flush_6' => 2, 'low_6' => 1];
            $aOrder = $typeOrder[$a['type']];
            $bOrder = $typeOrder[$b['type']];

            if ($aOrder !== $bOrder) {
                return $bOrder - $aOrder;
            }

            // Same type - use tie-breaking
            return $b['tie_value'] - $a['tie_value'];
        });

        return [
            'winner_id' => $candidates[0]['player_id'],
            'type' => $candidates[0]['type'],
        ];
    }

    /**
     * Check if a single hand has an instant win.
     */
    public function checkPlayerInstantWin(array $cards): ?array
    {
        // Check Four of a Kind
        $fourResult = $this->checkFourOfAKind($cards);
        if ($fourResult) {
            return $fourResult;
        }

        // Check Flush 6 (all same suit)
        $flushResult = $this->checkFlush6($cards);
        if ($flushResult) {
            return $flushResult;
        }

        // Check Low 6 (all cards rank <= 5)
        $lowResult = $this->checkLow6($cards);
        if ($lowResult) {
            return $lowResult;
        }

        return null;
    }

    private function checkFourOfAKind(array $cards): ?array
    {
        $rankCounts = [];
        foreach ($cards as $card) {
            $parsed = $this->parseCard($card);
            $rankCounts[$parsed['rank']] = ($rankCounts[$parsed['rank']] ?? 0) + 1;
        }

        foreach ($rankCounts as $rank => $count) {
            if ($count >= 4) {
                return [
                    'type' => 'four_of_a_kind',
                    'tie_value' => $this->getRankValue($rank) * 100, // tie-break by rank
                ];
            }
        }

        return null;
    }

    private function checkFlush6(array $cards): ?array
    {
        if (count($cards) !== 6) return null;

        $suits = [];
        foreach ($cards as $card) {
            $parsed = $this->parseCard($card);
            $suits[$parsed['suit']] = ($suits[$parsed['suit']] ?? 0) + 1;
        }

        foreach ($suits as $suit => $count) {
            if ($count === 6) {
                return [
                    'type' => 'flush_6',
                    'tie_value' => $this->getSuitValue($suit), // tie-break by suit
                ];
            }
        }

        return null;
    }

    private function checkLow6(array $cards): ?array
    {
        if (count($cards) !== 6) return null;

        foreach ($cards as $card) {
            $parsed = $this->parseCard($card);
            if ($this->getRankValue($parsed['rank']) > 5) {
                return null;
            }
        }

        // All cards rank <= 5, find highest for tie-break
        $highest = null;
        $highestSuit = null;
        foreach ($cards as $card) {
            $parsed = $this->parseCard($card);
            $val = $this->getRankValue($parsed['rank']);
            if ($highest === null || $val > $highest || ($val === $highest && $this->getSuitValue($parsed['suit']) > $this->getSuitValue($highestSuit))) {
                $highest = $val;
                $highestSuit = $parsed['suit'];
            }
        }

        return [
            'type' => 'low_6',
            'tie_value' => $highest * 10 + $this->getSuitValue($highestSuit),
        ];
    }

    // =========================================================================
    // TRICK-TAKING (Rounds 1-4)
    // =========================================================================

    /**
     * Validate if a card play is legal.
     * For face-up play: must be same suit as lead AND rank > current winning card.
     * For face-down (thiệp): any card is valid.
     */
    public function validatePlay(
        string $card,
        bool $faceDown,
        array $playerHand,
        ?string $leadSuit,
        ?string $currentWinningCard,
        bool $isLeadPlay,
        bool $isChungRound = false
    ): bool {
        // Card must be in player's hand
        if (!in_array($card, $playerHand)) {
            return false;
        }

        // Lead player always plays face-up
        if ($isLeadPlay) {
            return !$faceDown;
        }

        // In chưng/decision rounds, non-lead players may either submit
        // face-down for reveal, or play face-up using the normal catch rule.
        if ($isChungRound && $faceDown) {
            return true;
        }

        // Thiệp (face down) - always valid
        if ($faceDown) {
            return true;
        }

        // Face-up: must be same suit as lead AND greater than current winning card
        $parsed = $this->parseCard($card);
        if ($parsed['suit'] !== $leadSuit) {
            return false;
        }

        if ($currentWinningCard) {
            $winningParsed = $this->parseCard($currentWinningCard);
            if ($this->getRankValue($parsed['rank']) <= $this->getRankValue($winningParsed['rank'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine the winner of a round.
     * The highest face-up card of the lead suit wins.
     */
    public function evaluateRound(array $plays, string $leadSuit, bool $revealFaceDown = false): ?int
    {
        $winningPlayerId = null;
        $winningRankValue = -1;

        foreach ($plays as $play) {
            if ($play['is_face_down'] && !$revealFaceDown) {
                continue;
            }

            $parsed = $this->parseCard($play['card']);
            if ($parsed['suit'] !== $leadSuit) {
                continue;
            }

            $rankValue = $this->getRankValue($parsed['rank']);
            if ($rankValue > $winningRankValue) {
                $winningRankValue = $rankValue;
                $winningPlayerId = $play['player_id'];
            }
        }

        return $winningPlayerId;
    }

    /**
     * Get the current winning card on the table (highest face-up card of lead suit).
     */
    public function getCurrentWinningCard(array $plays, string $leadSuit): ?string
    {
        $winningCard = null;
        $winningRankValue = -1;

        foreach ($plays as $play) {
            if ($play['is_face_down']) continue;

            $parsed = $this->parseCard($play['card']);
            if ($parsed['suit'] !== $leadSuit) continue;

            $rankValue = $this->getRankValue($parsed['rank']);
            if ($rankValue > $winningRankValue) {
                $winningRankValue = $rankValue;
                $winningCard = $play['card'];
            }
        }

        return $winningCard;
    }

    // =========================================================================
    // POST ROUND 4 EVALUATION
    // =========================================================================

    /**
     * After round 4, determine who has tồn and what happens next.
     * Returns: ['eliminated' => [...ids], 'winner' => id|null, 'continue' => bool]
     */
    public function evaluatePostRound4(array $roundWinners, array $activePlayers): array
    {
        // Count tồn per player
        $tonCount = [];
        foreach ($activePlayers as $playerId) {
            $tonCount[$playerId] = 0;
        }
        foreach ($roundWinners as $winnerId) {
            if (isset($tonCount[$winnerId])) {
                $tonCount[$winnerId]++;
            }
        }

        // Gục tùng: no tồn
        $eliminated = [];
        $surviving = [];
        foreach ($tonCount as $playerId => $count) {
            if ($count === 0) {
                $eliminated[] = $playerId;
            } else {
                $surviving[] = $playerId;
            }
        }

        // Thắng tùng: only 1 player with tồn
        if (count($surviving) === 1) {
            return [
                'eliminated' => $eliminated,
                'winner' => $surviving[0],
                'win_type' => 'thang_tung',
                'continue' => false,
            ];
        }

        // Continue to rounds 5-6
        return [
            'eliminated' => $eliminated,
            'winner' => null,
            'win_type' => null,
            'continue' => true,
            'surviving' => $surviving,
        ];
    }

    // =========================================================================
    // SCORING
    // =========================================================================

    /**
     * Calculate scores after a game ends.
     * Returns: [player_id => score_delta]
     */
    public function calculateScores(
        ?int $winnerId,
        string $winType,
        array $eliminatedPlayerIds,
        array $hands,
        bool $thoiAchEnabled,
    ): array {
        $scores = [];

        // Winner scoring
        if ($winnerId) {
            switch ($winType) {
                case 'thang_tung':
                case 'instant_win':
                    $scores[$winnerId] = 2;
                    break;
                default: // normal win (round 6)
                    $scores[$winnerId] = 1;
                    break;
            }
        }

        // Gục tùng scoring
        foreach ($eliminatedPlayerIds as $playerId) {
            $scores[$playerId] = ($scores[$playerId] ?? 0) - 1;

            // Thối Ách: -1 per Ace remaining in hand when eliminated
            if ($thoiAchEnabled && isset($hands[$playerId])) {
                foreach ($hands[$playerId] as $card) {
                    $parsed = $this->parseCard($card);
                    if ($parsed['rank'] === 'A') {
                        $scores[$playerId]--;
                    }
                }
            }
        }

        return $scores;
    }

    // =========================================================================
    // AUTO-PLAY (Timeout)
    // =========================================================================

    /**
     * Choose a card to auto-play when player times out.
     * Strategy: thiệp (face down) the smallest card not of lead suit.
     * If all cards are lead suit, thiệp the smallest.
     */
    public function autoPlay(array $hand, ?string $leadSuit): array
    {
        if (empty($hand)) {
            return ['card' => null, 'face_down' => true];
        }

        // If this is lead play (no lead suit yet), play smallest card face up
        if ($leadSuit === null) {
            $sorted = $this->sortCards($hand);
            return ['card' => $sorted[0], 'face_down' => false];
        }

        // Prefer cards NOT of lead suit (smaller first)
        $nonLeadCards = [];
        $leadCards = [];

        foreach ($hand as $card) {
            $parsed = $this->parseCard($card);
            if ($parsed['suit'] !== $leadSuit) {
                $nonLeadCards[] = $card;
            } else {
                $leadCards[] = $card;
            }
        }

        if (!empty($nonLeadCards)) {
            $sorted = $this->sortCards($nonLeadCards);
            return ['card' => $sorted[0], 'face_down' => true];
        }

        // All cards are lead suit - play smallest face down
        $sorted = $this->sortCards($leadCards);
        return ['card' => $sorted[0], 'face_down' => true];
    }

    /**
     * Sort cards by rank value ascending.
     */
    public function sortCards(array $cards): array
    {
        usort($cards, function ($a, $b) {
            $parsedA = $this->parseCard($a);
            $parsedB = $this->parseCard($b);
            $rankDiff = $this->getRankValue($parsedA['rank']) - $this->getRankValue($parsedB['rank']);
            if ($rankDiff !== 0) return $rankDiff;
            return $this->getSuitValue($parsedA['suit']) - $this->getSuitValue($parsedB['suit']);
        });
        return $cards;
    }

    /**
     * Get next player in clockwise order.
     */
    public function getNextPlayer(int $currentPlayerId, array $activePlayers, array $seatPositions): ?int
    {
        if (empty($activePlayers)) return null;

        $currentSeat = $seatPositions[$currentPlayerId] ?? 0;
        $sorted = [];

        foreach ($activePlayers as $playerId) {
            $seat = $seatPositions[$playerId] ?? 0;
            $sorted[$playerId] = $seat;
        }

        asort($sorted);
        $playerOrder = array_keys($sorted);

        // Find current player position in order
        $currentIndex = array_search($currentPlayerId, $playerOrder);
        if ($currentIndex === false) {
            return $playerOrder[0] ?? null;
        }

        // Next in circular order
        $nextIndex = ($currentIndex + 1) % count($playerOrder);
        return $playerOrder[$nextIndex];
    }

    /**
     * Remove a card from a player's hand.
     */
    public function removeCardFromHand(array $hands, int $playerId, string $card): array
    {
        if (isset($hands[$playerId])) {
            $index = array_search($card, $hands[$playerId]);
            if ($index !== false) {
                unset($hands[$playerId][$index]);
                $hands[$playerId] = array_values($hands[$playerId]);
            }
        }
        return $hands;
    }

    /**
     * Count aces remaining in a player's hand.
     */
    public function countAcesInHand(array $hand): int
    {
        $count = 0;
        foreach ($hand as $card) {
            $parsed = $this->parseCard($card);
            if ($parsed['rank'] === 'A') {
                $count++;
            }
        }
        return $count;
    }
}
