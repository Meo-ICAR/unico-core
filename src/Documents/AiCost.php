<?php

namespace Unico\Core\Documents;

/** Costo in dollari di una chiamata AI, dai token e dai prezzi per milione di token in configurazione. */
class AiCost
{
    public static function usd(int $inputTokens, int $outputTokens): ?float
    {
        $in = config('unico-core.documents.ai.price_input');
        $out = config('unico-core.documents.ai.price_output');

        if ($in === null || $out === null) {
            return null;
        }

        return round(($inputTokens * (float) $in + $outputTokens * (float) $out) / 1_000_000, 6);
    }
}
