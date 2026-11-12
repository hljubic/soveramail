<?php

namespace App\Services\Ai\Style;

/**
 * Deterministic writing-style statistics from the user's own sent mail. No model
 * involved: greetings, sign-offs, signature block, length, formality, language,
 * characteristic vocabulary, recurring phrases and punctuation habits.
 *
 * Input: one entry per sent mail with the user's OWN text (quotes already stripped).
 * Output: a plain array that is stored as JSON and rendered into the draft prompt.
 */
final class StyleAnalyzer
{
    public const MAX_LIST = 25;

    /** Lines from the end of a mail that may belong to sign-off + signature. */
    private const TAIL = 12;

    /** Words that open a greeting line (lower-case, any language we expect). */
    private const GREETING_STARTS = [
        'poštovani', 'poštovana', 'poštovane', 'poštovanje', 'pozdrav', 'bok', 'hej', 'ej', 'dragi', 'draga', 'drage', 'zdravo', 'ćao', 'cao',
        'dobar', 'dobro', 'kolega', 'kolegice', 'dear', 'hi', 'hello', 'hey', 'good', 'greetings', 'hallo', 'liebe', 'lieber', 'sehr',
    ];

    /** Sign-off lines (lower-case, punctuation stripped). */
    private const CLOSING_STARTS = [
        'lijep pozdrav', 'lp', 'srdačan pozdrav', 'srdačno', 's poštovanjem', 'pozdrav', 'pozdravi', 'puno pozdrava', 'hvala', 'hvala unaprijed',
        'hvala i pozdrav', 'hvala i lijep pozdrav', 'vidimo se', 'čujemo se', 'javi se', 'javite se', 'sve najbolje', 'ugodan dan', 'ugodan vikend',
        'best', 'best regards', 'kind regards', 'regards', 'warm regards', 'thanks', 'thank you', 'many thanks', 'cheers', 'sincerely', 'all the best',
        'take care', 'talk soon', 'mit freundlichen grüßen', 'viele grüße', 'liebe grüße', 'cordialement',
    ];

    private const FORMAL_MARKERS = ['vi', 'vas', 'vam', 'vaš', 'vaša', 'vaše', 'vašeg', 'vašu', 'vašem', 'vašim', 'vašoj', 'poštovani', 'poštovana', 'poštovane', 'izvolite', 'ljubazno', 'molimo'];

    private const INFORMAL_MARKERS = ['ti', 'tebi', 'tebe', 'tvoj', 'tvoja', 'tvoje', 'tvog', 'tvom', 'bok', 'hej', 'ej', 'ćao', 'cao', 'aj', 'ajde', 'ok', 'okej', 'super', 'čujemo', 'vidimo'];

    private const HR_MARKERS = ['je', 'i', 'u', 'na', 'da', 'se', 'za', 'sam', 'smo', 'ste', 'su', 'hvala', 'molim', 'pozdrav', 'može', 'nije', 'ili', 'ako', 'kako', 'što', 'koji', 'koja', 'koje', 'bih', 'bi', 'ću', 'će', 'ćemo', 'privitku', 'prilogu'];

    private const EN_MARKERS = ['the', 'and', 'you', 'to', 'of', 'is', 'for', 'thanks', 'please', 'regards', 'best', 'with', 'that', 'this', 'will', 'would', 'have', 'are', 'attached', 'dear'];

    private const DE_MARKERS = ['und', 'der', 'die', 'das', 'ich', 'nicht', 'sie', 'mit', 'ist', 'wir', 'grüße', 'danke', 'bitte', 'sehr', 'geehrte'];

    private const STOPWORDS = [
        // hr
        'i', 'u', 'na', 'je', 'da', 'se', 'za', 'od', 'do', 'sa', 's', 'o', 'a', 'ali', 'ili', 'ako', 'kad', 'kada', 'kako', 'što', 'sto', 'koji', 'koja', 'koje', 'kojeg', 'koju', 'kojim', 'ovo', 'ova', 'ovaj', 'to', 'taj', 'ta', 'te', 'ti', 'tu', 'tamo', 'tako', 'samo', 'još', 'jos', 'već', 'vec', 'sam', 'si', 'smo', 'ste', 'su', 'bi', 'bih', 'bio', 'bila', 'bilo', 'bili', 'bile', 'biti', 'će', 'ce', 'ću', 'cu', 'ćemo', 'cemo', 'ćete', 'nije', 'ne', 'ni', 'niti', 'nema', 'ima', 'imam', 'imamo', 'imate', 'može', 'moze', 'mogu', 'možemo', 'možete', 'mi', 'me', 'moj', 'moja', 'moje', 'mene', 'meni', 'vi', 'vam', 'vas', 'vaš', 'vaša', 'vaše', 'nam', 'nas', 'naš', 'naša', 'naše', 'on', 'ona', 'ono', 'oni', 'one', 'ga', 'mu', 'joj', 'ih', 'im', 'njega', 'njemu', 'nju', 'njoj', 'njih', 'njima', 'jer', 'pa', 'po', 'pri', 'prije', 'poslije', 'nakon', 'oko', 'kroz', 'iz', 'kod', 'bez', 'među', 'nad', 'pod', 'pred', 'preko', 'prema', 'uz', 'zbog', 'radi', 'više', 'vise', 'manje', 'jako', 'vrlo', 'malo', 'puno', 'sve', 'svi', 'sva', 'svaki', 'svaka', 'svako', 'nešto', 'nesto', 'ništa', 'nista', 'netko', 'nitko', 'sad', 'sada', 'onda', 'ovdje', 'gdje', 'kamo', 'zašto', 'zasto', 'li', 'evo', 'eto', 'dakle', 'također', 'takoder', 'isto', 'inače', 'inace', 'možda', 'mozda', 'vjerojatno', 'naravno', 'npr', 'itd', 'tj', 'ok', 'hvala', 'molim', 'pozdrav', 'lp', 'lijep', 'srdačan', 'poštovani', 'poštovana', 'dragi', 'draga', 'bok', 'hej', 'dan', 'dana', 'danas', 'sutra', 'jučer', 'jucer', 'sat', 'sati', 'tjedan', 'tjedna', 'mjesec', 'godine', 'godina', 'pon', 'uto', 'sri', 'čet', 'pet', 'sub', 'ned', 'jedan', 'jedna', 'jedno', 'dva', 'dvije', 'tri', 'prvi', 'prva', 'prvo', 'drugi', 'druga', 'drugo', 'treba', 'trebam', 'trebamo', 'trebate', 'želim', 'zelim', 'htio', 'htjela', 'mislim', 'znam', 'vidim', 'kažem', 'kazem',
        // en
        'the', 'and', 'or', 'a', 'an', 'to', 'of', 'in', 'on', 'at', 'by', 'for', 'with', 'from', 'as', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'am', 'it', 'its', 'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she', 'we', 'they', 'my', 'your', 'our', 'their', 'his', 'her', 'them', 'us', 'him', 'do', 'does', 'did', 'have', 'has', 'had', 'will', 'would', 'can', 'could', 'should', 'shall', 'may', 'might', 'must', 'not', 'no', 'yes', 'so', 'if', 'then', 'than', 'but', 'about', 'into', 'over', 'after', 'before', 'again', 'just', 'also', 'very', 'more', 'most', 'some', 'any', 'all', 'each', 'other', 'such', 'only', 'own', 'same', 'too', 'here', 'there', 'when', 'where', 'why', 'how', 'what', 'which', 'who', 'whom', 'up', 'down', 'out', 'off', 'get', 'got', 'let', 'please', 'thanks', 'thank', 'regards', 'best', 'kind', 'dear', 'hi', 'hello', 'hey', 'ok', 'okay', 'well', 'still', 'much', 'many', 'via', 'per', 'etc', 'today', 'tomorrow', 'yesterday', 'week', 'month', 'year',
    ];

    /**
     * @param  array<int, array{id: int, text: string, sent_at: ?string, to: string[]}>  $mails  the user's own sent mail, own text only
     * @param  string[]  $others  own text of mails written by other people (for "distinctive" vocabulary); may be empty
     * @param  string[]  $ownNames  the user's name parts, so they are not counted as vocabulary / sign-off words
     */
    /** Mail sent from the user's account by an app, not by the user (scanner, calendar, drive…). */
    private const AUTOMATED = '/^(Created and shared using|This event has been|Invitation:|Accepted:|Declined:|Tentatively accepted:|Updated invitation|You have been invited|I\'ve shared an item with you|.* shared a (file|folder) with you)/imu';

    public function analyse(array $mails, array $others = [], array $ownNames = []): array
    {
        $mails = array_values(array_filter($mails, function ($m): bool {
            $text = trim((string) ($m['text'] ?? ''));

            return $text !== '' && ! preg_match(self::AUTOMATED, $text);
        }));
        $n = count($mails);
        if ($n === 0) {
            return ['mails' => 0];
        }

        $ownNames = array_map(fn ($w) => mb_strtolower($w), array_filter($ownNames, fn ($w) => mb_strlen($w) >= 2));

        // -------- signature: lines that recur at the end of many mails
        $tailFreq = [];
        $tailPos = [];
        $tails = [];
        foreach ($mails as $i => $m) {
            $lines = $this->lines($m['text']);
            $firstLine = (string) (array_values(array_filter($lines, fn ($l) => trim($l) !== ''))[0] ?? '');
            // the greeting line is never a signature line; a mail that is only a signature keeps it
            $firstKey = $this->greetingOf($firstLine) !== null || $this->inlineGreeting($firstLine) !== null ? $this->norm($firstLine) : "\0";
            $tail = array_slice($lines, -self::TAIL);
            $tails[$i] = $tail;
            $seen = [];
            foreach (array_reverse($tail) as $fromEnd => $line) {
                $key = $this->norm($line);
                // Signature lines are short labels (name, title, phone, URL): never the greeting
                // line, never a sentence — templated mails would otherwise count as "signature".
                if ($key === '' || mb_strlen($key) > 90 || isset($seen[$key]) || $key === $firstKey
                    || count(preg_split('/\s+/u', $key) ?: []) > 8 || preg_match('/[?!]$/u', $key)) {
                    continue;
                }
                $seen[$key] = true;
                $tailFreq[$key] = ($tailFreq[$key] ?? 0) + 1;
                $tailPos[$key][] = $fromEnd;
            }
        }
        $sigThreshold = max(3, (int) ceil($n * 0.25));
        $signatureKeys = [];
        foreach ($tailFreq as $key => $count) {
            // a lone first name under the sign-off is how the user signs, not a signature block
            if ($count >= $sigThreshold && ! $this->isClosing($key, $ownNames) && ! $this->isSignName($key, $ownNames)) {
                $signatureKeys[$key] = $count;
            }
        }
        // Order the signature block the way it appears (bottom-most last).
        uksort($signatureKeys, fn ($a, $b) => (array_sum($tailPos[$b]) / count($tailPos[$b])) <=> (array_sum($tailPos[$a]) / count($tailPos[$a])));
        $signatureLines = [];
        foreach (array_keys($signatureKeys) as $key) {
            $signatureLines[] = $this->surface($mails, $key);
        }
        $signatureShare = $signatureKeys === [] ? 0.0 : max($signatureKeys) / $n;

        // -------- per-mail pass
        $greetings = [];
        $closings = [];
        $signNames = [];
        $inlineGreeting = 0;
        $emptyMails = 0;
        $noGreeting = 0;
        $noClosing = 0;
        $wordCounts = [];
        $sentenceCounts = [];
        $paragraphCounts = [];
        $wordsPerSentence = [];
        $formal = 0;
        $informal = 0;
        $lang = ['hr' => 0, 'en' => 0, 'de' => 0, 'other' => 0];
        $punct = ['exclamation' => 0, 'question' => 0, 'emoji' => 0, 'smiley' => 0, 'ellipsis' => 0, 'list' => 0, 'lowercase_start' => 0, 'multi_exclamation' => 0];
        $df = [];          // word -> number of mails containing it
        $phraseDf = [];    // n-gram -> number of mails
        $bodies = [];
        $openers = [];          // first words of the first sentence
        $starters = [];         // first two words of any sentence
        $shortReplies = [];     // whole mails of ≤ 8 words
        $requests = [];         // sentences that ask for something
        $questions = [];        // sentences ending with ?
        $candidates = [];       // [sentence, mail index] for "characteristic sentences"
        $greetingsByReg = ['formal' => [], 'informal' => []];
        $closingsByReg = ['formal' => [], 'informal' => []];
        $ortho = ['diacritics' => 0, 'hr' => 0, 'hr_ascii' => 0, 'lower_after_greeting' => 0, 'with_greeting' => 0, 'capital_vi' => 0, 'comma_after_greeting' => 0];
        $timeFormats = ['10:00' => 0, '10h' => 0, '10 sati' => 0, 'u 10' => 0];
        $langByDomain = [];

        foreach ($mails as $i => $m) {
            $lines = $this->lines($m['text']);
            $nonEmpty = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
            if ($nonEmpty === []) {
                continue;
            }

            // greeting — on its own line, or inline ("Poštovanje, u redu, može…")
            $first = $nonEmpty[0];
            $inline = false;
            // "Poštovanje, u redu, može u 14h." is a greeting plus a sentence, not a six-word greeting
            if (($split = $this->inlineGreeting($first)) !== null) {
                [$g, $restOfLine] = $split;
                $nonEmpty[0] = $restOfLine;
                $inline = true;
            } else {
                $g = $this->greetingOf($first);
            }
            if ($g !== null && ! $inline) {
                array_shift($nonEmpty);
            }

            // signature + closing from the end
            $rest = [];
            $closingFound = null;
            $tailWindow = self::TAIL;
            for ($j = count($nonEmpty) - 1; $j >= 0; $j--) {
                $line = $nonEmpty[$j];
                $key = $this->norm($line);
                $inTail = (count($nonEmpty) - 1 - $j) < $tailWindow;
                if ($inTail && isset($signatureKeys[$key])) {
                    continue;
                }
                if ($inTail && $closingFound === null && $this->isClosing($key, $ownNames)) {
                    $closingFound = $this->closingLabel($line, $ownNames);

                    continue;
                }
                if ($inTail && $closingFound === null && $this->isBareName($key, $ownNames)) {
                    $signNames[trim($line, ' ,.')] = ($signNames[trim($line, ' ,.')] ?? 0) + 1; // "Davor" alone under the closing

                    continue;
                }
                array_unshift($rest, $line);
            }
            $body = trim(implode("\n", $rest));
            if ($body === '' && $g === null && $closingFound === null) {
                // attachment-only send: nothing but the signature — not a writing sample
                $emptyMails++;
                unset($mails[$i]);

                continue;
            }
            $bodies[$i] = $body;

            if ($g !== null) {
                $greetings[$g] = ($greetings[$g] ?? 0) + 1;
                $ortho['with_greeting']++;
                if ($inline || preg_match('/,\s*$/u', $first)) {
                    $ortho['comma_after_greeting']++;
                }
                if ($inline) {
                    $inlineGreeting++;
                }
            } else {
                $noGreeting++;
            }
            if ($closingFound !== null) {
                $closings[$closingFound] = ($closings[$closingFound] ?? 0) + 1;
            } else {
                $noClosing++;
            }

            // length
            $words = $this->words($body);
            $wc = count($words);
            $wordCounts[] = $wc;
            $sentences = $this->sentences($body);
            $sc = max(1, count($sentences));
            $sentenceCounts[] = $sc;
            $wordsPerSentence[] = $wc / $sc;
            $paragraphCounts[] = max(1, count(array_filter(preg_split("/\n\s*\n/", $body) ?: [], fn ($p) => trim($p) !== '')));

            // formality + language (over the whole own text, greeting included)
            $all = $this->words(mb_strtolower($m['text']));
            $counts = array_count_values($all);
            $f = 0;
            $inf = 0;
            foreach (self::FORMAL_MARKERS as $w) {
                $f += $counts[$w] ?? 0;
            }
            foreach (self::INFORMAL_MARKERS as $w) {
                $inf += $counts[$w] ?? 0;
            }
            // capitalised mid-sentence "Vi/Vam/Vaš" is the strongest formal signal in Croatian
            $capitalVi = preg_match_all('/(?<=[a-zčćšžđ,]\s)(Vi|Vas|Vam|Vaš\w*)\b/u', $m['text']);
            $f += $capitalVi;
            $register = $f > $inf ? 'formal' : ($inf > $f ? 'informal' : 'neutral');
            if ($register === 'formal') {
                $formal++;
            } elseif ($register === 'informal') {
                $informal++;
            }
            if ($register !== 'neutral') {
                if ($g !== null) {
                    $greetingsByReg[$register][$g] = ($greetingsByReg[$register][$g] ?? 0) + 1;
                }
                if ($closingFound !== null) {
                    $closingsByReg[$register][$closingFound] = ($closingsByReg[$register][$closingFound] ?? 0) + 1;
                }
            }
            if ($capitalVi > 0) {
                $ortho['capital_vi']++;
            }
            if ($g !== null && $body !== '' && preg_match('/^\p{Ll}/u', $body)) {
                $ortho['lower_after_greeting']++;
            }

            // sentence-level knowledge: how mails open, how sentences start, requests, questions
            if ($sentences !== []) {
                $firstWords = array_slice($this->words($sentences[0]), 0, 3);
                if (count($firstWords) === 3) {
                    $this->count($openers, implode(' ', $firstWords));
                }
            }
            foreach ($sentences as $sentence) {
                $sw = $this->words($sentence);
                $len = count($sw);
                if ($len >= 3) {
                    $this->count($starters, implode(' ', array_slice($sw, 0, 2)));
                }
                if ($len >= 4 && $len <= 30) {
                    $clean = trim($sentence);
                    if (str_ends_with($clean, '?')) {
                        $questions[] = [$clean, $i];
                    }
                    if (preg_match('/\b(molim|možete li|mozete li|može li|moze li|možeš li|javite|javi|pošaljite|posalji|pošalji|prijavite|provjerite|potvrdite|trebam|trebalo bi|bilo bi dobro|please|could you|can you|would you|let me know|send me)\b/iu', $clean)) {
                        $requests[] = [$clean, $i];
                    }
                    if ($len >= 6) {
                        $candidates[] = [$clean, $i];
                    }
                }
            }
            if ($wc > 0 && $wc <= 8) {
                // links are noise in a style example; a bare link is not a reply at all
                $short = preg_replace('/\s+/u', ' ', preg_replace('~https?://\S+~u', '<link>', $body) ?? $body) ?? $body;
                if (preg_match('/\p{L}{2,}/u', str_replace('<link>', '', $short))) {
                    $this->count($shortReplies, $short);
                }
            }
            $timeFormats['10:00'] += preg_match('/\b\d{1,2}:\d{2}\b/u', $body) ? 1 : 0;
            $timeFormats['10h'] += preg_match('/\b\d{1,2}\s?h\b/u', $body) ? 1 : 0;
            $timeFormats['10 sati'] += preg_match('/\b\d{1,2}\s?sati\b/u', $body) ? 1 : 0;
            $timeFormats['u 10'] += preg_match('/\bu \d{1,2}\b(?!\s?[:h.]|\s?sati|\.)/u', $body) ? 1 : 0;

            $hr = $en = $de = 0;
            foreach (self::HR_MARKERS as $w) {
                $hr += $counts[$w] ?? 0;
            }
            foreach (self::EN_MARKERS as $w) {
                $en += $counts[$w] ?? 0;
            }
            foreach (self::DE_MARKERS as $w) {
                $de += $counts[$w] ?? 0;
            }
            $diacritics = preg_match_all('/[čćšžđ]/iu', $m['text']);
            $hr += $diacritics * 2;
            $mailLang = $hr === 0 && $en === 0 && $de === 0 ? 'other' : ($hr >= $en && $hr >= $de ? 'hr' : ($en >= $de ? 'en' : 'de'));
            $lang[$mailLang]++;
            if ($diacritics > 0) {
                $ortho['diacritics']++;
            }
            if ($mailLang === 'hr') {
                $ortho['hr']++;
                if ($diacritics === 0) {
                    $ortho['hr_ascii']++;
                }
            }
            foreach (array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) (explode('@', (string) $e)[1] ?? ''))), $m['to'] ?? []))) as $domain) {
                $langByDomain[$domain][$mailLang] = ($langByDomain[$domain][$mailLang] ?? 0) + 1;
            }

            // punctuation habits
            $ex = substr_count($body, '!');
            $punct['exclamation'] += $ex;
            $punct['question'] += substr_count($body, '?');
            $punct['multi_exclamation'] += str_contains($body, '!!') ? 1 : 0;
            $punct['emoji'] += preg_match('/\p{Extended_Pictographic}/u', $m['text']) ? 1 : 0;
            $punct['smiley'] += preg_match('/(?<!\w)[:;]-?[)(DPp]/', $m['text']) ? 1 : 0;
            $punct['ellipsis'] += (str_contains($body, '...') || str_contains($body, '…')) ? 1 : 0;
            $punct['list'] += preg_match('/^\s*([-•*]|\d+[.)])\s+\S/mu', $body) ? 1 : 0;
            $punct['lowercase_start'] += ($body !== '' && preg_match('/^\p{Ll}/u', $body)) ? 1 : 0;

            // vocabulary (document frequency); the recipients' names are not "vocabulary"
            $skip = array_merge($ownNames, $this->recipientNames($m['to'] ?? []));
            $tokens = $this->tokens($body);
            foreach (array_unique($tokens) as $t) {
                if (mb_strlen($t) >= 4 && ! in_array($t, self::STOPWORDS, true) && ! in_array($t, $skip, true)) {
                    $df[$t] = ($df[$t] ?? 0) + 1;
                }
            }
            foreach ($this->ngrams($tokens, $skip) as $p) {
                $phraseDf[$p] = ($phraseDf[$p] ?? 0) + 1;
            }
        }

        // Shares are relative to real writing samples only.
        $mails = array_values($mails);
        $n = max(1, $n - $emptyMails);

        // -------- distinctive words: used by the user much more than by correspondents
        $otherDf = [];
        $nOther = 0;
        foreach ($others as $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            $nOther++;
            foreach (array_unique($this->tokens($text)) as $t) {
                if (mb_strlen($t) >= 4) {
                    $otherDf[$t] = ($otherDf[$t] ?? 0) + 1;
                }
            }
        }
        $minDf = max(3, (int) ceil($n * 0.03));
        $distinctive = [];
        foreach ($df as $w => $c) {
            if ($c < $minDf) {
                continue;
            }
            $score = log(($c + 1) / ($n + 2)) - log((($otherDf[$w] ?? 0) + 1) / ($nOther + 2));
            if ($nOther === 0 || $score > 0.4) {
                $distinctive[$w] = ['word' => $w, 'mails' => $c, 'score' => round($score, 3)];
            }
        }
        usort($distinctive, fn ($a, $b) => [$b['score'] * min($b['mails'], 30), $b['mails']] <=> [$a['score'] * min($a['mails'], 30), $a['mails']]);

        // -------- characteristic sentences: the ones richest in the user's distinctive words
        $scoreOf = [];
        foreach ($distinctive as $w => $d) {
            $scoreOf[$w] = $d['score'];
        }
        $scored = [];
        foreach ($candidates as [$sentence, $mi]) {
            $sum = 0.0;
            foreach (array_unique($this->tokens($sentence)) as $t) {
                $sum += $scoreOf[$t] ?? 0.0;
            }
            $scored[] = ['s' => $sentence, 'i' => $mi, 'score' => $sum / sqrt(max(1, count($this->words($sentence))))];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $examples = $this->pickSentences($scored, 10);
        $requestList = $this->pickSentences(array_map(fn ($r) => ['s' => $r[0], 'i' => $r[1], 'score' => 0.0], $requests), 8);
        $questionList = $this->pickSentences(array_map(fn ($r) => ['s' => $r[0], 'i' => $r[1], 'score' => 0.0], $questions), 6);

        $domains = [];
        foreach ($langByDomain as $domain => $langs) {
            $total = array_sum($langs);
            if ($total < 2 || $domain === '') {
                continue;
            }
            arsort($langs);
            $domains[] = ['domain' => $domain, 'mails' => $total, 'language' => (string) array_key_first($langs), 'share' => round(reset($langs) / $total, 2)];
        }
        usort($domains, fn ($a, $b) => $b['mails'] <=> $a['mails']);

        arsort($df);
        $frequent = [];
        foreach (array_slice($df, 0, self::MAX_LIST, true) as $w => $c) {
            if ($c >= $minDf) {
                $frequent[] = ['word' => $w, 'mails' => $c];
            }
        }

        // phrases: keep n-grams seen in ≥ minDf mails, drop a bigram when a trigram with the same support contains it
        $phrases = array_filter($phraseDf, fn ($c) => $c >= $minDf);
        arsort($phrases);
        $kept = [];
        foreach ($phrases as $p => $c) {
            foreach ($kept as $k => $kc) {
                if ($kc >= $c && str_contains(" {$k} ", " {$p} ")) {
                    continue 2;
                }
            }
            $kept[$p] = $c;
            if (count($kept) >= self::MAX_LIST) {
                break;
            }
        }

        $greetingList = $this->ranked($greetings, $n);
        $closingList = $this->ranked($closings, $n);

        $counted = max(1, count($wordCounts));

        return [
            'mails' => $n,
            'period' => [
                'from' => $this->minDate($mails),
                'to' => $this->maxDate($mails),
            ],
            'language' => array_map(fn ($c) => round($c / $n, 2), $lang),
            'formality' => [
                'formal' => round($formal / $n, 2),
                'informal' => round($informal / $n, 2),
                'neutral' => round(($n - $formal - $informal) / $n, 2),
            ],
            'length' => [
                'median_words' => $this->median($wordCounts),
                'avg_words' => round(array_sum($wordCounts) / $counted, 1),
                'avg_sentences' => round(array_sum($sentenceCounts) / $counted, 1),
                'avg_words_per_sentence' => round(array_sum($wordsPerSentence) / $counted, 1),
                'avg_paragraphs' => round(array_sum($paragraphCounts) / $counted, 1),
                'short_share' => round(count(array_filter($wordCounts, fn ($c) => $c <= 40)) / $counted, 2),
                'long_share' => round(count(array_filter($wordCounts, fn ($c) => $c >= 150)) / $counted, 2),
            ],
            'greetings' => $greetingList,
            'no_greeting_share' => round($noGreeting / $n, 2),
            'inline_greeting_share' => round($inlineGreeting / $n, 2),
            'closings' => $closingList,
            'no_closing_share' => round($noClosing / $n, 2),
            'sign_name' => $this->ranked($signNames, $n)[0] ?? null,
            'signature' => [
                'lines' => $signatureLines,
                'share' => round($signatureShare, 2),
            ],
            'frequent_words' => $frequent,
            'distinctive_words' => array_values(array_slice($distinctive, 0, self::MAX_LIST)),
            'phrases' => array_map(fn ($p, $c) => ['text' => $p, 'mails' => $c], array_keys($kept), $kept),
            'punctuation' => [
                'exclamations_per_mail' => round($punct['exclamation'] / $n, 2),
                'questions_per_mail' => round($punct['question'] / $n, 2),
                'multi_exclamation_share' => round($punct['multi_exclamation'] / $n, 2),
                'emoji_share' => round($punct['emoji'] / $n, 2),
                'smiley_share' => round($punct['smiley'] / $n, 2),
                'ellipsis_share' => round($punct['ellipsis'] / $n, 2),
                'list_share' => round($punct['list'] / $n, 2),
                'lowercase_start_share' => round($punct['lowercase_start'] / $n, 2),
            ],
            'openers' => $this->topCounted($openers, 10, 2),
            'sentence_starters' => $this->topCounted($starters, 12, 3),
            'short_replies' => $this->topCounted($shortReplies, 10, 1),
            'requests' => $requestList,
            'questions' => $questionList,
            'example_sentences' => $examples,
            'by_register' => [
                'formal' => ['mails' => $formal, 'greetings' => $this->ranked($greetingsByReg['formal'], max(1, $formal)), 'closings' => $this->ranked($closingsByReg['formal'], max(1, $formal))],
                'informal' => ['mails' => $informal, 'greetings' => $this->ranked($greetingsByReg['informal'], max(1, $informal)), 'closings' => $this->ranked($closingsByReg['informal'], max(1, $informal))],
            ],
            'orthography' => [
                'diacritics_share' => round($ortho['diacritics'] / $n, 2),
                'ascii_croatian_share' => $ortho['hr'] > 0 ? round($ortho['hr_ascii'] / $ortho['hr'], 2) : 0.0,
                'lowercase_after_greeting_share' => $ortho['with_greeting'] > 0 ? round($ortho['lower_after_greeting'] / $ortho['with_greeting'], 2) : 0.0,
                'comma_after_greeting_share' => $ortho['with_greeting'] > 0 ? round($ortho['comma_after_greeting'] / $ortho['with_greeting'], 2) : 0.0,
                'capital_vi_share' => round($ortho['capital_vi'] / $n, 2),
            ],
            'time_formats' => array_filter($timeFormats),
            'language_by_domain' => array_slice($domains, 0, 10),
            'sample_ids' => $this->pickSamples($mails, $wordCounts),
        ];
    }

    /** Count a surface form under its normalised key (first spelling seen is kept). */
    private function count(array &$bucket, string $text): void
    {
        $key = mb_strtolower(trim(preg_replace('/[\s]+/u', ' ', rtrim(trim($text), '.,;:!')) ?? $text));
        if ($key === '') {
            return;
        }
        if (! isset($bucket[$key])) {
            $bucket[$key] = ['text' => trim($text), 'count' => 0];
        }
        $bucket[$key]['count']++;
    }

    private function topCounted(array $bucket, int $limit, int $min): array
    {
        $rows = array_values(array_filter($bucket, fn ($r) => $r['count'] >= $min));
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Up to $limit distinct sentences, at most one per mail, in the given order.
     *
     * @param  array<int, array{s: string, i: int, score: float}>  $rows
     * @return string[]
     */
    private function pickSentences(array $rows, int $limit): array
    {
        $out = [];
        $seenMail = [];
        $seenText = [];
        foreach ($rows as $r) {
            $key = mb_strtolower($r['s']);
            if (isset($seenMail[$r['i']]) || isset($seenText[$key])) {
                continue;
            }
            $seenMail[$r['i']] = true;
            $seenText[$key] = true;
            $out[] = $r['s'];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- pieces

    /** @return string[] lines, without "--" signature delimiters */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('rtrim', explode("\n", str_replace("\r\n", "\n", $text))), fn ($l) => ! preg_match('/^\s*-{2,}\s*$/', $l)));
    }

    private function norm(string $line): string
    {
        $line = mb_strtolower(trim($line));
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;

        return trim($line, " \t-–—_|*");
    }

    /** The most common original spelling of a normalised tail line. */
    private function surface(array $mails, string $key): string
    {
        $forms = [];
        foreach ($mails as $m) {
            foreach (array_slice($this->lines($m['text']), -self::TAIL) as $line) {
                if ($this->norm($line) === $key) {
                    $forms[trim($line)] = ($forms[trim($line)] ?? 0) + 1;
                }
            }
        }
        arsort($forms);

        // Gmail renders bold as *…* in text/plain; the user never typed those.
        return trim((string) array_key_first($forms), '*_ ');
    }

    /** "Bok Ana," -> "Bok {name}," ; null when the first line is not a greeting. */
    private function greetingOf(string $line): ?string
    {
        $line = trim($line);
        $words = preg_split('/\s+/u', $line) ?: [];
        if ($words === [] || count($words) > 6) {
            return null;
        }
        $firstWord = mb_strtolower(trim($words[0], ',.!:;'));
        $endsLikeGreeting = (bool) preg_match('/[,!:]$/u', $line);
        if (! in_array($firstWord, self::GREETING_STARTS, true) && ! ($endsLikeGreeting && count($words) <= 3)) {
            return null;
        }
        $out = [];
        foreach ($words as $i => $w) {
            $bare = trim($w, ',.!:;');
            $lower = mb_strtolower($bare);
            if ($i > 0 && $bare !== '' && preg_match('/^\p{Lu}/u', $bare) && ! in_array($lower, self::GREETING_STARTS, true) && ! in_array($lower, ['svi', 'sve', 'svima', 'all', 'team', 'kolege', 'kolegice', 'gospodine', 'gospođo', 'gđo', 'gosp', 'prof', 'dr', 'mr', 'mrs', 'ms'], true)) {
                $out[] = '{name}'.mb_substr($w, mb_strlen($bare));
            } else {
                $out[] = $w;
            }
        }
        // collapse "{name} {name}" (first + last name)
        $joined = implode(' ', $out);

        return preg_replace('/(\{name\}\s+)+\{name\}/u', '{name}', $joined) ?? $joined;
    }

    /**
     * "Poštovanje, rad je na granici." -> ["Poštovanje,", "rad je na granici."]; null when the
     * line does not start with a greeting word followed by a comma and more text.
     *
     * @return array{0: string, 1: string}|null
     */
    private function inlineGreeting(string $line): ?array
    {
        $line = trim($line);
        if (! preg_match('/^((?:\p{L}+\s){0,2}\p{L}+)\s*,\s+(\S.*)$/u', $line, $m)) {
            return null;
        }
        $head = $m[1];
        $rest = trim($m[2]);
        $firstWord = mb_strtolower(explode(' ', $head)[0]);
        if (! in_array($firstWord, self::GREETING_STARTS, true) || count($this->words($rest)) < 2) {
            return null;
        }
        $g = $this->greetingOf($head.',');

        return $g === null ? null : [$g, $rest];
    }

    private function isClosing(string $key, array $ownNames): bool
    {
        $k = trim(preg_replace('/[,.!:;]+$/u', '', $key) ?? $key);
        // strip a trailing own name: "lijep pozdrav, davor"
        $parts = preg_split('/[,\s]+/u', $k) ?: [];
        while ($parts !== [] && in_array(end($parts), $ownNames, true)) {
            array_pop($parts);
        }
        $k = implode(' ', $parts);
        if ($k === '' || mb_strlen($k) > 40 || count($parts) > 4) {
            return false;
        }
        foreach (self::CLOSING_STARTS as $c) {
            if ($k === $c || str_starts_with($k, $c.' ') || str_starts_with($k, $c.',')) {
                return true;
            }
        }

        return false;
    }

    /** A single own-name token ("davor"), i.e. how the user signs under the sign-off. */
    private function isSignName(string $key, array $ownNames): bool
    {
        $k = trim($key, ' ,.!');

        return $k !== '' && ! str_contains($k, ' ') && in_array($k, $ownNames, true);
    }

    private function isBareName(string $key, array $ownNames): bool
    {
        $parts = preg_split('/[,\s]+/u', trim($key, ' ,.!')) ?: [];

        return $parts !== [] && count($parts) <= 3 && count(array_filter($parts, fn ($p) => in_array($p, $ownNames, true))) === count($parts);
    }

    /** "Lijep pozdrav, Davor" -> "Lijep pozdrav" (original casing, own name removed). */
    private function closingLabel(string $line, array $ownNames): string
    {
        $line = trim($line);
        $line = preg_replace('/[,.!:;]+$/u', '', $line) ?? $line;
        $parts = preg_split('/\s+/u', $line) ?: [];
        while ($parts !== [] && in_array(mb_strtolower(trim(end($parts), ',.')), $ownNames, true)) {
            array_pop($parts);
        }
        $out = trim(implode(' ', $parts), ' ,');

        return $out === '' ? $line : $out;
    }

    /** @return string[] */
    private function words(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return string[] */
    private function sentences(string $text): array
    {
        return preg_split('/(?<=[.!?])\s+|\n+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Lower-case letter-only tokens, in order. URLs and e-mail addresses are dropped. */
    private function tokens(string $text): array
    {
        $text = preg_replace('~https?://\S+|\S+@\S+~u', ' ', mb_strtolower($text)) ?? $text;
        preg_match_all('/\p{L}+(?:[\'’-]\p{L}+)?/u', $text, $m);

        return $m[0];
    }

    /** Name tokens of the recipients ("ivo.kovacic@x" -> ivo, kovacic). */
    private function recipientNames(array $emails): array
    {
        $out = [];
        foreach ($emails as $e) {
            $local = explode('@', mb_strtolower((string) $e))[0] ?? '';
            foreach (preg_split('/[._\-\d]+/', $local) ?: [] as $p) {
                if (mb_strlen($p) >= 3) {
                    $out[] = $p;
                }
            }
        }

        return $out;
    }

    /** Bigrams and trigrams worth keeping (unique per mail). */
    private function ngrams(array $tokens, array $ownNames): array
    {
        $out = [];
        $count = count($tokens);
        for ($len = 2; $len <= 3; $len++) {
            for ($i = 0; $i + $len <= $count; $i++) {
                $slice = array_slice($tokens, $i, $len);
                $content = array_filter($slice, fn ($t) => ! in_array($t, self::STOPWORDS, true) && mb_strlen($t) >= 3);
                if ($content === [] || array_intersect($slice, $ownNames) !== []) {
                    continue;
                }
                $out[implode(' ', $slice)] = true;
            }
        }

        return array_keys($out);
    }

    private function ranked(array $counts, int $n): array
    {
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, 8, true) as $text => $c) {
            $out[] = ['text' => $text, 'mails' => $c, 'share' => round($c / $n, 2)];
        }

        return $out;
    }

    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return (int) round(count($values) % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2);
    }

    private function minDate(array $mails): ?string
    {
        $dates = array_filter(array_column($mails, 'sent_at'));

        return $dates === [] ? null : min($dates);
    }

    private function maxDate(array $mails): ?string
    {
        $dates = array_filter(array_column($mails, 'sent_at'));

        return $dates === [] ? null : max($dates);
    }

    /**
     * Up to 12 mails for the model to read: recent ones, spread over short / medium / long.
     *
     * @return int[]
     */
    /** Representative mails handed to the model that writes the style guide. */
    public const SAMPLE_MAILS = 30;

    private function pickSamples(array $mails, array $wordCounts): array
    {
        $rows = [];
        foreach ($mails as $i => $m) {
            $rows[] = ['id' => (int) $m['id'], 'words' => $wordCounts[$i] ?? 0, 'sent_at' => (string) ($m['sent_at'] ?? '')];
        }
        $rows = array_filter($rows, fn ($r) => $r['words'] >= 8);
        usort($rows, fn ($a, $b) => strcmp($b['sent_at'], $a['sent_at']));
        // A spread over the last 300 mails (not only the newest few), by length, so the
        // guide sees students, partners and colleagues alike.
        $recent = array_slice($rows, 0, 300);

        $short = array_values(array_filter($recent, fn ($r) => $r['words'] <= 40));
        $medium = array_values(array_filter($recent, fn ($r) => $r['words'] > 40 && $r['words'] < 150));
        $long = array_values(array_filter($recent, fn ($r) => $r['words'] >= 150));
        $spread = function (array $bucket, int $take): array {
            if (count($bucket) <= $take) {
                return $bucket;
            }
            $step = count($bucket) / $take;

            return array_map(fn ($i) => $bucket[(int) floor($i * $step)], range(0, $take - 1));
        };

        $picked = [];
        foreach ([[$short, 8], [$medium, 14], [$long, 6]] as [$bucket, $take]) {
            foreach ($spread($bucket, $take) as $r) {
                $picked[$r['id']] = true;
            }
        }
        foreach ($recent as $r) {
            if (count($picked) >= self::SAMPLE_MAILS) {
                break;
            }
            $picked[$r['id']] = true;
        }

        return array_keys($picked);
    }
}
