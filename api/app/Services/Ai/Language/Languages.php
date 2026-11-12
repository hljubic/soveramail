<?php

namespace App\Services\Ai\Language;

/**
 * Languages the reply-language setting can take (ISO 639-1 codes) and, for the
 * ones where "standard literary form" needs spelling out, what that means.
 */
final class Languages
{
    /** code => [English name, native name] */
    public const ALL = [
        'af' => ['Afrikaans', 'Afrikaans'], 'am' => ['Amharic', 'አማርኛ'], 'ar' => ['Arabic', 'العربية'], 'az' => ['Azerbaijani', 'Azərbaycan dili'],
        'be' => ['Belarusian', 'Беларуская'], 'bg' => ['Bulgarian', 'Български'], 'bn' => ['Bengali', 'বাংলা'], 'bs' => ['Bosnian', 'Bosanski'],
        'ca' => ['Catalan', 'Català'], 'cs' => ['Czech', 'Čeština'], 'cy' => ['Welsh', 'Cymraeg'], 'da' => ['Danish', 'Dansk'],
        'de' => ['German', 'Deutsch'], 'el' => ['Greek', 'Ελληνικά'], 'en' => ['English', 'English'], 'eo' => ['Esperanto', 'Esperanto'],
        'es' => ['Spanish', 'Español'], 'et' => ['Estonian', 'Eesti'], 'eu' => ['Basque', 'Euskara'], 'fa' => ['Persian', 'فارسی'],
        'fi' => ['Finnish', 'Suomi'], 'fr' => ['French', 'Français'], 'fy' => ['Frisian', 'Frysk'], 'ga' => ['Irish', 'Gaeilge'],
        'gd' => ['Scottish Gaelic', 'Gàidhlig'], 'gl' => ['Galician', 'Galego'], 'gu' => ['Gujarati', 'ગુજરાતી'], 'ha' => ['Hausa', 'Hausa'],
        'he' => ['Hebrew', 'עברית'], 'hi' => ['Hindi', 'हिन्दी'], 'hr' => ['Croatian', 'Hrvatski'], 'hu' => ['Hungarian', 'Magyar'],
        'hy' => ['Armenian', 'Հայերեն'], 'id' => ['Indonesian', 'Bahasa Indonesia'], 'ig' => ['Igbo', 'Igbo'], 'is' => ['Icelandic', 'Íslenska'],
        'it' => ['Italian', 'Italiano'], 'ja' => ['Japanese', '日本語'], 'jv' => ['Javanese', 'Basa Jawa'], 'ka' => ['Georgian', 'ქართული'],
        'km' => ['Khmer', 'ខ្មែរ'], 'ko' => ['Korean', '한국어'], 'ku' => ['Kurdish', 'Kurdî'], 'la' => ['Latin', 'Latina'],
        'lo' => ['Lao', 'ລາວ'], 'lt' => ['Lithuanian', 'Lietuvių'], 'lv' => ['Latvian', 'Latviešu'], 'mk' => ['Macedonian', 'Македонски'],
        'ml' => ['Malayalam', 'മലയാളം'], 'mn' => ['Mongolian', 'Монгол'], 'ms' => ['Malay', 'Bahasa Melayu'], 'mt' => ['Maltese', 'Malti'],
        'nl' => ['Dutch', 'Nederlands'], 'no' => ['Norwegian', 'Norsk'], 'oc' => ['Occitan', 'Occitan'], 'pl' => ['Polish', 'Polski'],
        'pt' => ['Portuguese', 'Português'], 'ro' => ['Romanian', 'Română'], 'ru' => ['Russian', 'Русский'], 'sk' => ['Slovak', 'Slovenčina'],
        'sl' => ['Slovenian', 'Slovenščina'], 'so' => ['Somali', 'Soomaali'], 'sq' => ['Albanian', 'Shqip'], 'sr' => ['Serbian', 'Srpski / Српски'],
        'sv' => ['Swedish', 'Svenska'], 'sw' => ['Swahili', 'Kiswahili'], 'ta' => ['Tamil', 'தமிழ்'], 'th' => ['Thai', 'ไทย'],
        'tl' => ['Filipino', 'Filipino'], 'tr' => ['Turkish', 'Türkçe'], 'tt' => ['Tatar', 'Татар'], 'uk' => ['Ukrainian', 'Українська'],
        'ur' => ['Urdu', 'اردو'], 'uz' => ['Uzbek', 'Oʻzbek'], 'vi' => ['Vietnamese', 'Tiếng Việt'], 'xh' => ['Xhosa', 'isiXhosa'],
        'yo' => ['Yoruba', 'Yorùbá'], 'zh' => ['Chinese', '中文'],
    ];

    /**
     * What "standard literary form" means where it is not obvious. The default for
     * every other language is simply "the standard written form".
     */
    private const STANDARD = [
        'hr' => 'standard Croatian (hrvatski standardni/književni jezik): ijekavian, Latin script, Croatian vocabulary (tko, što, tjedan, sveučilište, tisuću, kruh, siječanj) — never Serbian or Bosnian forms',
        'sr' => 'standard Serbian: ekavian unless the user writes ijekavian, in the script the user uses (Cyrillic or Latin)',
        'bs' => 'standard Bosnian: ijekavian, Latin script',
        'en' => 'standard English, keeping the spelling variant the user uses (British or American)',
        'de' => 'Standarddeutsch (Hochdeutsch), no dialect; Swiss "ss" only if the user writes that way',
        'pt' => 'standard Portuguese in the variant the user uses (European or Brazilian)',
        'es' => 'standard Spanish in the variant the user uses',
        'no' => 'standard Norwegian (Bokmål unless the user writes Nynorsk)',
        'zh' => 'standard written Chinese in the script the user uses (Simplified or Traditional)',
        'el' => 'standard Modern Greek (monotonic)',
        'ar' => 'Modern Standard Arabic',
        'it' => 'standard Italian, no dialect',
        'nl' => 'standard Dutch (Algemeen Nederlands)',
        'sl' => 'standard Slovenian (knjižna slovenščina)',
        'cs' => 'standard Czech (spisovná čeština), not colloquial obecná čeština',
        'sk' => 'standard Slovak (spisovná slovenčina)',
        'mk' => 'standard Macedonian, Cyrillic script',
        'uk' => 'standard Ukrainian',
        'ru' => 'standard Russian',
        'pl' => 'standard Polish',
        'hu' => 'standard Hungarian',
    ];

    /** @return string[] */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    public static function name(string $code): string
    {
        return self::ALL[$code][0] ?? strtoupper($code);
    }

    /** One line for the prompt: the language and what its standard form means. */
    public static function standardForm(string $code): string
    {
        return self::STANDARD[$code] ?? 'standard written '.self::name($code);
    }

    /** @return array<int, array{code: string, name: string, native: string}> */
    public static function list(): array
    {
        $out = [];
        foreach (self::ALL as $code => [$name, $native]) {
            $out[] = ['code' => $code, 'name' => $name, 'native' => $native];
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** Map the detector's fine-grained codes (sr-Latn, pt-BR, nb, zh-Hans…) onto the list above. */
    public static function normalise(string $code): ?string
    {
        $base = strtolower(explode('-', $code)[0]);
        $base = match ($base) {
            'nb', 'nn' => 'no',
            default => $base,
        };

        return isset(self::ALL[$base]) ? $base : null;
    }
}
