<?php

namespace Unico\Core\Pdf;

/**
 * Punti di firma di un modulo PDF ricavati dai segnaposto scritti nel testo, ad esempio
 * `{{Sig1_ev_:signer1:signature:dimension(width=50mm, height=9mm)}}`: `signer1` è il cliente, `signer2` il collaboratore.
 * Ogni segnaposto diventa uno slot (pagina, posizione e misura in punti); più segnaposto dello stesso firmatario
 * sono più punti di firma dello stesso slot. Coordinate con origine in alto a sinistra, come le vuole il provider.
 */
class SignatureAnchors
{
    private const POINTS_PER_MM = 72 / 25.4;

    private const SIGNERS = [
        1 => ['slot' => 'cliente', 'role' => 'client'],
        2 => ['slot' => 'collaboratore', 'role' => 'fornitore'],
    ];

    /**
     * Legge il PDF con `pdftotext -bbox-layout`.
     *
     * @return list<array{page: int, x: int, y: int, width: int, height: int, role: string, slot: string}>
     */
    public function scan(string $pdfPath, string $binary = 'pdftotext'): array
    {
        $html = shell_exec(sprintf('%s -bbox-layout %s - 2>/dev/null', escapeshellcmd($binary), escapeshellarg($pdfPath)));

        return is_string($html) ? $this->fromBoundingBoxes($html) : [];
    }

    /**
     * @return list<array{page: int, x: int, y: int, width: int, height: int, role: string, slot: string}>
     */
    public function fromBoundingBoxes(string $html): array
    {
        $slots = [];
        $pages = preg_split('/<page /', $html, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_slice($pages, 1) as $index => $page) {
            $pageWidth = preg_match('/width="([\d.]+)"/', $page, $m) ? (float) $m[1] : 595.0;

            preg_match_all('/<line xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*?)<\/line>/s', $page, $lines, PREG_SET_ORDER);

            foreach ($lines as $line) {
                $text = html_entity_decode(trim(strip_tags($line[5])), ENT_QUOTES | ENT_HTML5);
                $text = (string) preg_replace('/\s+/', ' ', $text);

                if (preg_match('/\{\{Sig\d+_ev_:signer(\d+):signature:dimension\(width=(\d+)mm,\s*height=(\d+)mm\)\}\}/', $text, $anchor) !== 1) {
                    continue;
                }

                $signer = (int) $anchor[1];
                $width = round((int) $anchor[2] * self::POINTS_PER_MM);
                $height = round((int) $anchor[3] * self::POINTS_PER_MM);
                $x = min((float) $line[1], $pageWidth - $width);

                $slots[] = [
                    'page' => $index + 1,
                    'x' => (int) round(max(0, $x)),
                    // La firma sta sopra la riga del segnaposto: il bordo basso del riquadro è quello del segnaposto.
                    'y' => (int) round(max(0, (float) $line[4] - $height)),
                    'width' => (int) $width,
                    'height' => (int) $height,
                    'role' => self::SIGNERS[$signer]['role'] ?? 'client',
                    'slot' => self::SIGNERS[$signer]['slot'] ?? 'firmatario'.$signer,
                ];
            }
        }

        return $slots;
    }
}
