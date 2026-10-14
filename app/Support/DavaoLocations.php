<?php

namespace App\Support;

/**
 * Davao City's 11 administrative districts and their 182 barangays, in 3 congressional districts.
 * Names as listed on Wikipedia "Districts of Davao City" (checked 2026-10-14; same list as the team's
 * research checklist). Not yet checked against the PSA PSGC list: "Balenggaeng" / "Balengaeng" and
 * "Biao Guinga" / "Biao Guianga" — findBarangay() ignores small spelling differences, so either is found.
 *
 * Mirrored on the client at src/lib/davaoLocations.ts — keep both in sync by hand.
 */
final class DavaoLocations
{
    public const CONGRESSIONAL = [
        '1st' => ['Poblacion', 'Talomo'],
        '2nd' => ['Agdao', 'Buhangin', 'Bunawan', 'Paquibato'],
        '3rd' => ['Baguio', 'Calinan', 'Marilog', 'Toril', 'Tugbok'],
    ];

    public const BARANGAYS = [
        'Poblacion' => [
            'Barangay 1-A', 'Barangay 2-A', 'Barangay 3-A', 'Barangay 4-A', 'Barangay 5-A',
            'Barangay 6-A', 'Barangay 7-A', 'Barangay 8-A', 'Barangay 9-A', 'Barangay 10-A',
            'Barangay 11-B', 'Barangay 12-B', 'Barangay 13-B', 'Barangay 14-B', 'Barangay 15-B',
            'Barangay 16-B', 'Barangay 17-B', 'Barangay 18-B', 'Barangay 19-B', 'Barangay 20-B',
            'Barangay 21-C', 'Barangay 22-C', 'Barangay 23-C', 'Barangay 24-C', 'Barangay 25-C',
            'Barangay 26-C', 'Barangay 27-C', 'Barangay 28-C', 'Barangay 29-C', 'Barangay 30-C',
            'Barangay 31-D', 'Barangay 32-D', 'Barangay 33-D', 'Barangay 34-D', 'Barangay 35-D',
            'Barangay 36-D', 'Barangay 37-D', 'Barangay 38-D', 'Barangay 39-D', 'Barangay 40-D',
        ],
        'Talomo' => [
            'Bago Aplaya', 'Bago Gallera', 'Baliok', 'Bucana', 'Catalunan Grande', 'Catalunan Pequeño', 'Dumoy',
            'Langub', 'Ma-a', 'Magtuod', 'Matina Aplaya', 'Matina Crossing', 'Matina Pangi', 'Talomo Proper',
        ],
        'Agdao' => [
            'Agdao Proper', 'Centro (San Juan)', 'Gov. Paciano Bangoy', 'Gov. Vicente Duterte', 'Kap. Tomas Monteverde, Sr.',
            'Lapu-Lapu', 'Leon Garcia', 'Rafael Castillo', 'San Antonio', 'Ubalde', 'Wilfredo Aquino',
        ],
        'Buhangin' => [
            'Acacia', 'Alfonso Angliongto Sr.', 'Buhangin Proper', 'Cabantian', 'Callawa', 'Communal', 'Indangan',
            'Mandug', 'Pampanga', 'Sasa', 'Tigatto', 'Vicente Hizon Sr.', 'Waan',
        ],
        'Bunawan' => [
            'Alejandra Navarro (Lasang)', 'Bunawan Proper', 'Gatungan', 'Ilang', 'Mahayag', 'Mudiang', 'Panacan',
            'San Isidro (Licanan)', 'Tibungco',
        ],
        'Paquibato' => [
            'Colosas', 'Fatima (Benowang)', 'Lumiad', 'Mabuhay', 'Malabog', 'Mapula', 'Panalum', 'Pandaitan',
            'Paquibato Proper', 'Paradise Embak', 'Salapawan', 'Sumimao', 'Tapak',
        ],
        'Baguio' => [
            'Baguio Proper', 'Cadalian', 'Carmen', 'Gumalang', 'Malagos', 'Tambobong', 'Tawan-Tawan', 'Wines',
        ],
        'Calinan' => [
            'Biao Joaquin', 'Calinan Proper', 'Cawayan', 'Dacudao', 'Dalagdag', 'Dominga', 'Inayangan', 'Lacson',
            'Lamanan', 'Lampianao', 'Megkawayan', 'Pangyan', 'Riverside', 'Saloy', 'Sirib', 'Subasta', 'Talomo River',
            'Tamayong', 'Wangan',
        ],
        'Marilog' => [
            'Baganihan', 'Bantol', 'Buda', 'Dalag', 'Datu Salumay', 'Gumitan', 'Magsaysay', 'Malamba', 'Marilog Proper',
            'Salaysay', 'Suawan (Tuli)', 'Tamugan',
        ],
        'Toril' => [
            'Alambre', 'Atan-Awe', 'Bangkas Heights', 'Baracatan', 'Bato', 'Bayabas', 'Binugao', 'Camansi', 'Catigan',
            'Crossing Bayabas', 'Daliao', 'Daliaon Plantation', 'Eden', 'Kilate', 'Lizada', 'Lubogan', 'Marapangi',
            'Mulig', 'Sibulan', 'Sirawan', 'Tagluno', 'Tagurano', 'Tibuloy', 'Toril Proper', 'Tungkalan',
        ],
        'Tugbok' => [
            'Angalan', 'Bago Oshiro', 'Balenggaeng', 'Biao Escuela', 'Biao Guinga', 'Los Amigos', 'Manambulan',
            'Manuel Guianga', 'Matina Biao', 'Mintal', 'New Carmen', 'New Valencia', 'Santo Niño', 'Tacunan',
            'Tagakpan', 'Talandang', 'Tugbok Proper', 'Ula',
        ],
    ];

    /** Spellings seen in the research that differ from the names above (not yet settled against PSA). */
    private const OTHER_SPELLINGS = [
        'balengaeng' => 'Balenggaeng',
        'biao guianga' => 'Biao Guinga',
    ];

    /** @return list<string> */
    public static function districts(): array
    {
        return array_keys(self::BARANGAYS);
    }

    /**
     * The official name and district of a barangay as someone typed it — ignores case, dots, commas, ñ/n, a leading
     * "Barangay"/"Brgy.", and accepts either half of a name like "Alejandra Navarro (Lasang)".
     *
     * @return array{name: string, district: string}|null
     */
    public static function findBarangay(?string $typed): ?array
    {
        $key = self::normalize($typed);
        if ($key === '') {
            return null;
        }
        $key = self::normalize(self::OTHER_SPELLINGS[$key] ?? $key);

        foreach (self::BARANGAYS as $district => $names) {
            foreach ($names as $name) {
                if (in_array($key, self::matchKeys($name), true)) {
                    return ['name' => $name, 'district' => $district];
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function matchKeys(string $name): array
    {
        $keys = [self::normalize($name)];
        if (preg_match('/^(.*?)\s*\((.*)\)\s*$/u', $name, $m)) {
            $keys[] = self::normalize($m[1]);
            $keys[] = self::normalize($m[2]);
        }

        return $keys;
    }

    private static function normalize(?string $value): string
    {
        $value = strtr(mb_strtolower(trim((string) $value)), ['ñ' => 'n']);
        $value = preg_replace('/^(barangay|brgy\.?)\s+/u', '', $value);
        $value = preg_replace('/[.,()]/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }
}
