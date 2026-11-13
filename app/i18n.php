<?php
declare(strict_types=1);

const SUPPORTED_LANGUAGES = ['it', 'en'];

function current_language(): string
{
    $language = isset($_SESSION['ui_language']) ? (string)$_SESSION['ui_language'] : 'it';
    return in_array($language, SUPPORTED_LANGUAGES, true) ? $language : 'it';
}

function set_current_language(string $language): void
{
    $_SESSION['ui_language'] = in_array($language, SUPPORTED_LANGUAGES, true) ? $language : 'it';
}

function translations(string $language): array
{
    static $cache = [];
    if (!isset($cache[$language])) {
        $file = __DIR__ . '/../lang/' . $language . '.php';
        $cache[$language] = is_file($file) ? require $file : [];
    }
    return $cache[$language];
}

function t(string $key, array $replace = []): string
{
    $italian = translations('it');
    $selected = translations(current_language());
    $text = (string)($selected[$key] ?? $italian[$key] ?? $key);
    foreach ($replace as $name => $value) $text = str_replace('{' . $name . '}', (string)$value, $text);
    return $text;
}

function language_name(string $code): string
{
    return match ($code) { 'en' => 'English', default => 'Italiano' };
}

function localized_months(): array
{
    return current_language() === 'en'
        ? ['01'=>'January','02'=>'February','03'=>'March','04'=>'April','05'=>'May','06'=>'June','07'=>'July','08'=>'August','09'=>'September','10'=>'October','11'=>'November','12'=>'December']
        : ['01'=>'Gennaio','02'=>'Febbraio','03'=>'Marzo','04'=>'Aprile','05'=>'Maggio','06'=>'Giugno','07'=>'Luglio','08'=>'Agosto','09'=>'Settembre','10'=>'Ottobre','11'=>'Novembre','12'=>'Dicembre'];
}

function format_ui_date(?string $value): string
{
    if ($value === null || $value === '') return '—';
    $timestamp = strtotime($value);
    if ($timestamp === false) return $value;
    return date(current_language() === 'en' ? 'm/d/Y' : 'd/m/Y', $timestamp);
}

function utility_label(string $code, string $fallback = ''): string
{
    $key = match ($code) {
        'luce' => 'nav.electricity', 'gas' => 'nav.gas', 'acqua' => 'nav.water',
        'tari' => 'nav.waste', 'bonifica' => 'nav.reclamation', default => '',
    };
    return $key !== '' ? t($key) : ($fallback !== '' ? $fallback : $code);
}
