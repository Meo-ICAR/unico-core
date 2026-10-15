<?php

use Unico\Core\Access\AccessChecker;

if (! function_exists('checkPiano')) {
    /**
     * Verifica se una funzionalità è accessibile all'utente: piano della sua company per questa app e ruoli EmployeeType
     * (vedi Unico\Core\Access\AccessChecker). Il risultato è memorizzato per la durata della richiesta.
     * `$write`: la funzione modifica i dati; i ruoli di sola lettura (l'ispettore) la ottengono solo in lettura.
     */
    function checkPiano(string $feature, ?string $callerClass = null, bool $write = false): bool
    {
        return app(AccessChecker::class)->check($feature, $callerClass, null, $write);
    }
}

if (! function_exists('controllaPartitaIva')) {
    /**
     * Controllo Partita IVA (da cervedxml): 11 cifre italiane con checksum, oppure 13 caratteri UE
     * (prefisso nazione; per IT anche il checksum sulla parte numerica).
     * Restituisce il messaggio di errore, oppure null se il valore è valido.
     */
    function controllaPartitaIva(string $value): ?string
    {
        $value = strtoupper($value);
        $length = strlen($value);

        if (! in_array($length, [11, 13], true)) {
            return 'La Partita IVA deve essere di 11 caratteri (Italia) o 13 caratteri (UE).';
        }

        $euCountries = [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
            'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
        ];

        if ($length === 13) {
            $prefix = substr($value, 0, 2);
            if (! ctype_alpha($prefix) || ! in_array($prefix, $euCountries, true)) {
                return 'Prefisso nazione non valido per Partita IVA UE.';
            }

            if ($prefix === 'IT') {
                $suffix = substr($value, 2);
                if (! ctype_digit($suffix)) {
                    return 'La parte numerica della Partita IVA italiana deve contenere solo cifre.';
                }
                if (! partitaIvaItalianaChecksum($suffix)) {
                    return 'La Partita IVA italiana non è valida (checksum errato).';
                }
            }

            return null;
        }

        if (! ctype_digit($value)) {
            return 'La Partita IVA italiana deve contenere solo cifre.';
        }
        if (! partitaIvaItalianaChecksum($value)) {
            return 'La Partita IVA non è valida.';
        }

        return null;
    }
}

if (! function_exists('partitaIvaValida')) {
    /** True se `controllaPartitaIva()` non trova errori. */
    function partitaIvaValida(string $value): bool
    {
        return controllaPartitaIva($value) === null;
    }
}

if (! function_exists('partitaIvaItalianaChecksum')) {
    /**
     * Checksum della Partita IVA italiana (11 cifre): le posizioni pari (0-based) si sommano,
     * quelle dispari si raddoppiano e, se superano 9, si toglie 9. Il totale deve essere divisibile per 10.
     */
    function partitaIvaItalianaChecksum(string $value): bool
    {
        $s = 0;
        for ($i = 0; $i < 11; $i++) {
            $n = ord($value[$i]) - ord('0');
            if (($i & 1) === 1) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $s += $n;
        }

        return $s % 10 === 0;
    }
}
