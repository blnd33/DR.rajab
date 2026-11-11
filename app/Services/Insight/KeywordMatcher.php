<?php

namespace App\Services\Insight;

use App\Support\Locale\DigitConverter;

/**
 * Typed words → the closest ready-made questions. No AI: each question lists words in English,
 * Arabic and Kurdish; the question with the most matching words comes first. Arabic and Kurdish
 * spellings are folded together (ي/ی, ك/ک, أ/ا, ة/ه, no vowel marks) so either keyboard works.
 */
final class KeywordMatcher
{
    /**
     * @param  list<QuickQuestion>  $questions
     * @return list<QuickQuestion> best first, at most $limit
     */
    public function match(string $text, array $questions, int $limit = 4): array
    {
        $input = self::normalize($text);

        if ($input === '') {
            return [];
        }

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scored = [];

        foreach ($questions as $index => $question) {
            $score = 0;

            foreach (array_unique(array_map(self::normalize(...), $question->keywords)) as $keyword) {
                if ($keyword !== '' && self::contains($input, $tokens, $keyword)) {
                    $score++;
                }
            }

            if ($score > 0) {
                $scored[] = ['question' => $question, 'score' => $score, 'index' => $index];
            }
        }

        usort($scored, fn ($a, $b) => [$b['score'], $a['index']] <=> [$a['score'], $b['index']]);

        return array_map(fn ($row) => $row['question'], array_slice($scored, 0, $limit));
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim((string) DigitConverter::toWestern($text)));
        // Arabic vowel marks and tatweel.
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);

        return strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک',
            'ة' => 'ه', 'ە' => 'ه',
        ]);
    }

    /**
     * Latin words match the start of a typed word ("expir" ≠ "about out"); Arabic and Kurdish
     * words match anywhere, since they take prefixes (ال، بال، و).
     *
     * @param  list<string>  $tokens
     */
    private static function contains(string $input, array $tokens, string $keyword): bool
    {
        if (str_contains($keyword, ' ') || preg_match('/[^\x00-\x7F]/', $keyword)) {
            return mb_strlen($keyword) >= 2 && str_contains($input, $keyword);
        }

        foreach ($tokens as $token) {
            if (str_starts_with($token, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
