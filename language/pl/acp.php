<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Minos
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'MINOS_ACP_EXPLAIN'          => 'Minos wysyła nowe posty do bramy Wergiliusz, która ocenia ich treść i odsyła werdykt na adres webhooka tego forum. Na czas oceny post czeka w kolejce do zatwierdzenia. Do bramy trafia wyłącznie tekst postu (pierwsze 3000 znaków, z cytatami i ich autorami, bez formatowania, z tekstem atrybutów title i alt), liczba linków, do 10 domen, na które prowadzą, i informacja, czy to pierwszy post autora — nigdy e-mail, adres IP ani identyfikator użytkownika.',
	'MINOS_ACP_SAVED'            => 'Ustawienia zostały zapisane.',
	'MINOS_ACP_NOT_READY'        => 'Rozszerzenie nie wstrzymuje teraz żadnych postów: włącz je i podaj adres bramy, klucz API oraz sekret webhooka.',
	'MINOS_ACP_READY'            => 'Rozszerzenie działa: nowe posty są oceniane przed publikacją.',

	'MINOS_SETTINGS'             => 'Ustawienia',
	'MINOS_ENABLED'              => 'Oceniaj nowe posty',
	'MINOS_ENABLED_EXPLAIN'      => 'Wyłączone rozszerzenie nic nie robi: nowe posty publikują się jak dawniej, nic nie jest wysyłane do bramy, a werdykty są odrzucane (webhook odpowiada 404). Posty, które czekały na werdykt, zostają w kolejce do decyzji moderatora.',
	'MINOS_GATEWAY_URL'          => 'Adres bramy',
	'MINOS_GATEWAY_URL_EXPLAIN'  => 'Zwykle https://gateway.wergiliusz.app. Tylko https:// (http:// wyłącznie dla localhost, do testów z atrapą bramy).',
	'MINOS_API_KEY'              => 'Klucz API',
	'MINOS_API_KEY_EXPLAIN'      => 'Klucz wgb2b_… otrzymany od operatora. Pozostaw puste, aby zachować zapisany klucz. Strona pokazuje tylko jego początek.',
	'MINOS_WEBHOOK_SECRET'       => 'Sekret webhooka',
	'MINOS_WEBHOOK_SECRET_EXPLAIN' => 'Sekret podany jednorazowo razem z kluczem; służy do sprawdzania podpisu werdyktów. Pozostaw puste, aby zachować zapisany sekret.',
	'MINOS_STORED'               => 'Zapisany',
	'MINOS_NOT_STORED'           => 'Brak',
	'MINOS_PROFILE'              => 'Profil oceny',
	'MINOS_PROFILE_EXPLAIN'      => 'Profil musi być dozwolony dla klucza. Forum dla młodzieży ocenia surowiej.',
	'MINOS_PROFILE_FORUM_ADULT'  => 'Forum dla dorosłych (forum_adult)',
	'MINOS_PROFILE_FORUM_TEEN'   => 'Forum dla młodzieży (forum_teen)',
	'MINOS_FAIL_MODE'            => 'Tryb awarii',
	'MINOS_FAIL_MODE_EXPLAIN'    => 'Co zrobić z postem, którego brama nie oceniła: gdy odpowie „nieocenione”, gdy werdykt nie nadejdzie w wyznaczonym czasie albo gdy odrzuci żądanie z powodu konfiguracji. Minos nigdy nie zgaduje werdyktu.',
	'MINOS_FAIL_MODE_FAIL_OPEN'  => 'Publikuj (fail-open)',
	'MINOS_FAIL_MODE_FAIL_CLOSED' => 'Zostaw w kolejce do zatwierdzenia (fail-closed)',
	'MINOS_TIMEOUT'              => 'Czas oczekiwania na werdykt',
	'MINOS_TIMEOUT_EXPLAIN'      => 'W minutach, co najmniej 20. Brama próbuje doręczyć werdykt przez 15 minut; po tym czasie (i zapasie) post obsługuje tryb awarii. Jeśli tryb fail-open opublikował post, a werdykt przyjdzie później, zostanie zastosowany, chyba że moderator lub autor zmienił już post.',
	'MINOS_CENSORED'             => 'Werdykt „ocenzurowane”',
	'MINOS_CENSORED_EXPLAIN'     => 'Publikacja zastępuje tekst postu wersją z zamaskowanymi fragmentami (bez formatowania). Post dłuższy niż 3000 znaków zawsze zostaje w kolejce: brama widziała tylko jego początek. Z tego samego powodu werdykt „bezpieczne” dla takiego postu obsługuje tryb awarii.',
	'MINOS_CENSORED_PUBLISH'     => 'Publikuj tekst z zamaskowanymi fragmentami',
	'MINOS_CENSORED_HOLD'        => 'Zostaw w kolejce do zatwierdzenia',
	'MINOS_BLOCKED'              => 'Werdykt „zablokowane”',
	'MINOS_BLOCKED_EXPLAIN'      => 'Usunięcie jest miękkie: moderator może post przywrócić.',
	'MINOS_BLOCKED_HOLD'         => 'Zostaw w kolejce do zatwierdzenia',
	'MINOS_BLOCKED_DELETE'       => 'Usuń miękko',
	'MINOS_FORUMS'               => 'Fora',
	'MINOS_FORUMS_EXPLAIN'       => 'Fora, w których nowe posty są oceniane. Bez zaznaczenia: wszystkie fora.',
	'MINOS_ALL_FORUMS'           => 'Teraz: wszystkie fora.',

	'MINOS_WEBHOOK'              => 'Adres webhooka',
	'MINOS_WEBHOOK_EXPLAIN'      => 'Przekaż ten adres operatorowi bramy przy rejestracji klucza. Musi być publicznym adresem https:// w domenie forum; brama nie wysyła werdyktów na adresy IP ani przez przekierowania.',

	'MINOS_LAST_ERROR'           => 'Ostatni błąd konfiguracji',
	'MINOS_LAST_ERROR_EXPLAIN'   => 'Brama odrzuciła żądanie, a posty obsłużono zgodnie z trybem awarii. Wpis jest też w dzienniku błędów.',

	'MINOS_RECENT'               => 'Ostatnio oceniane posty',
	'MINOS_RECENT_EXPLAIN'       => 'Oczekujące na werdykt: %1$d, w kolejce do zatwierdzenia: %2$d. Wpisy są usuwane 30 dni po rozstrzygnięciu.',
	'MINOS_RECENT_EMPTY'         => 'Brak postów.',
	'MINOS_COL_POST'             => 'Post',
	'MINOS_COL_TIME'             => 'Wysłany',
	'MINOS_COL_STATUS'           => 'Stan',
	'MINOS_COL_VERDICT'          => 'Werdykt',
	'MINOS_COL_CATEGORIES'       => 'Kategorie',
	'MINOS_COL_ERROR'            => 'Kod błędu',
	'MINOS_SUPPORT'              => 'potrzebne wsparcie',
	'MINOS_TRUNCATED'            => 'oceniono 3000 pierwszych znaków',
	'MINOS_MASK_MANUAL'          => 'zamaskowany tekst wymaga ręcznego zastosowania',
	'MINOS_MASKED_TEXT'          => 'Tekst zamaskowany przez bramę',
	'MINOS_OPEN_IN_MCP'          => 'Otwórz w panelu moderatora',

	'MINOS_STATUS_QUEUED'        => 'Do wysłania',
	'MINOS_STATUS_PENDING'       => 'W ocenie',
	'MINOS_STATUS_RECEIVED'      => 'Werdykt odebrany',
	'MINOS_STATUS_PUBLISHED'     => 'Opublikowany',
	'MINOS_STATUS_PUBLISHED_FAIL_OPEN' => 'Opublikowany bez oceny (fail-open)',
	'MINOS_STATUS_MASKED'        => 'Opublikowany z maskowaniem',
	'MINOS_STATUS_HELD'          => 'W kolejce do zatwierdzenia',
	'MINOS_STATUS_DELETED'       => 'Usunięty (miękko)',
	'MINOS_STATUS_SUPERSEDED'    => 'Rozstrzygnięty przez moderatora',

	'MINOS_ERROR_GATEWAY_URL'    => 'Adres bramy musi zaczynać się od https:// (http:// tylko dla localhost) i nie może zawierać danych logowania, parametrów ani kotwicy.',
	'MINOS_ERROR_API_KEY'        => 'Klucz API ma postać wgb2b_… (litery, cyfry, „_” i „-”).',
	'MINOS_ERROR_WEBHOOK_SECRET' => 'Sekret webhooka to od 16 do 255 widocznych znaków ASCII, bez spacji.',
	'MINOS_ERROR_CHOICE'         => 'Wybrano nieznaną wartość jednego z ustawień.',
	'MINOS_ERROR_TIMEOUT'        => 'Czas oczekiwania musi mieścić się w przedziale od 20 do 1440 minut.',

	'MINOS_REFUSAL_BRAK_KLUCZA'         => 'Brama nie zna tego klucza API. Sprawdź klucz.',
	'MINOS_REFUSAL_NIE_TA_POWIERZCHNIA' => 'Ten klucz nie jest kluczem B2B. Poproś operatora o klucz wgb2b_….',
	'MINOS_REFUSAL_BRAK_WEBHOOKA'       => 'Klucz nie ma działającego adresu webhooka. Przekaż operatorowi adres webhooka z tej strony.',
	'MINOS_REFUSAL_PROFIL_NIEDOZWOLONY' => 'Klucz nie obejmuje wybranego profilu oceny.',
	'MINOS_REFUSAL_NIE_ZNALEZIONO'      => 'Pod tym adresem brama nie przyjmuje postów. Sprawdź adres bramy.',
	'MINOS_REFUSAL_REDIRECT'            => 'Brama odpowiedziała przekierowaniem. Sprawdź adres bramy.',
	'MINOS_REFUSAL_OTHER'               => 'Brama odrzuciła żądanie. Zgłoś kod operatorowi bramy.',
));
