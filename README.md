# Minos — moderacja postów dla phpBB

Rozszerzenie phpBB 3.3, które przed publikacją wysyła nowe posty do bramy Wergiliusz
(usługa Minos) i stosuje werdykt, który brama odsyła podpisanym webhookiem: publikuje post,
publikuje go z zamaskowanymi fragmentami albo zostawia w kolejce do zatwierdzenia.

> **Stan:** trasa B2B bramy nie jest jeszcze otwarta produkcyjnie. Do czasu jej otwarcia
> rozszerzenie można uruchomić z atrapą bramy (zob. `docs/development.md`).

## Wymagania

- phpBB 3.3, PHP 7.4 lub nowszy z rozszerzeniem cURL;
- klucz B2B (`wgb2b_…`) i sekret webhooka od operatora bramy;
- forum dostępne pod publicznym adresem `https://` w domenie (nie adres IP): tam brama
  odsyła werdykty.

## Instalacja

1. Pobierz paczkę ZIP z wydania (albo zbuduj ją poleceniem `bash bin/build-zip.sh`; plik
   pojawi się w `build/`). Kopia repozytorium bez katalogu `vendor/` nie zadziała.
2. Rozpakuj ją do katalogu `ext/` forum, tak aby powstał katalog `ext/minos/moderation/`.
3. W panelu administracji: **Dostosuj → Zarządzaj rozszerzeniami → „Minos — moderacja
   postów” → Włącz**.
4. Otwórz **Rozszerzenia → Minos → Ustawienia moderacji**, wpisz klucz i sekret, przekaż
   operatorowi adres webhooka (patrz niżej) i włącz ocenianie.

Po instalacji rozszerzenie jest wyłączone i niczego nie wstrzymuje, dopóki nie włączysz
oceniania i nie podasz adresu bramy, klucza oraz sekretu.

## Ustawienia

| Ustawienie | Domyślnie | Znaczenie |
|---|---|---|
| Oceniaj nowe posty | wyłączone | Po wyłączeniu nowe posty publikują się jak dawniej; posty, które już czekają, zostaną obsłużone do końca. |
| Adres bramy | `https://gateway.wergiliusz.app` | Tylko `https://`. `http://` jest dozwolone wyłącznie dla `localhost` / `127.0.0.1` (testy z atrapą bramy). |
| Klucz API | — | Klucz `wgb2b_…`. Strona pokazuje tylko jego początek; puste pole zachowuje zapisany klucz. |
| Sekret webhooka | — | Sekret podany raz, razem z kluczem. Strona pokazuje tylko jego początek; puste pole zachowuje zapisany sekret. |
| Profil oceny | `forum_adult` | `forum_adult` (forum dla dorosłych) albo `forum_teen` (forum dla młodzieży, ocena surowsza). Profil musi być dozwolony dla klucza. |
| Tryb awarii | fail-open | Co zrobić z postem bez werdyktu — patrz „Tryb awarii”. |
| Czas oczekiwania na werdykt | 20 minut | 16–1440 minut. Brama próbuje doręczyć werdykt przez 15 minut; po tym czasie (z zapasem) post obsługuje tryb awarii. |
| Werdykt „ocenzurowane” | publikuj z maskowaniem | Publikacja tekstu z zamaskowanymi fragmentami albo pozostawienie w kolejce. |
| Werdykt „zablokowane” | zostaw w kolejce | Pozostawienie w kolejce albo miękkie usunięcie (moderator może post przywrócić). |
| Fora | wszystkie | Fora, w których nowe posty są oceniane. |

Klucz i sekret są przechowywane w tabeli `config_text` phpBB, która nie trafia do pamięci
podręcznej ładowanej przy każdej stronie. Nie pojawiają się w żadnym dzienniku ani
komunikacie błędu.

## Adres webhooka

Strona ustawień pokazuje adres, który trzeba przekazać operatorowi bramy przy rejestracji
klucza, na przykład:

- `https://forum.example.pl/app.php/minos/webhook`, albo
- `https://forum.example.pl/minos/webhook`, jeśli forum ma włączone przyjazne adresy URL.

Brama wysyła werdykty tylko na adres `https://` z nazwą domeny (nigdy na adres IP), tylko do
hostów zapisanych przy kluczu i nie podąża za przekierowaniami.

## Jak to działa

1. Użytkownik wysyła nowy temat lub odpowiedź. Rozszerzenie kieruje post do kolejki do
   zatwierdzenia (autor widzi zwykły komunikat phpBB o poście czekającym na akceptację) i
   od razu wysyła go do bramy.
2. Brama przyjmuje post (`202`) i ocenia go w tle — dla kluczy darmowych nawet do 15 minut.
3. Werdykt przychodzi na adres webhooka, podpisany sekretem. Rozszerzenie sprawdza podpis
   (niepodpisane, sfałszowane i przeterminowane doręczenia są odrzucane), odpowiada od razu
   i dopiero potem stosuje werdykt:

   | Werdykt | Skutek |
   |---|---|
   | `bezpieczne` | post zostaje opublikowany |
   | `ocenzurowane` | zależnie od ustawienia: publikacja tekstu z zamaskowanymi fragmentami (bez formatowania; oryginał zostaje zachowany w tabeli rozszerzenia) albo kolejka. Post dłuższy niż 3000 znaków i werdykt bez zamaskowanego tekstu zawsze zostają w kolejce. |
   | `zablokowane` | zależnie od ustawienia: kolejka albo miękkie usunięcie |
   | `nieocenione` | tryb awarii |

4. Każda decyzja rozszerzenia trafia do dziennika moderatorów (autor wpisu: Anonymous, opis:
   „Minos …”).

Ten sam werdykt doręczony dwa razy jest stosowany raz. Jeśli moderator zatwierdził lub
usunął post, zanim przyszedł werdykt, decyzja moderatora zostaje, a werdykt jest pomijany.
Jeśli autor poprawi post, który czeka na werdykt, post zostaje w kolejce i jest oceniany od
nowa; werdykt dla poprzedniej wersji nie zostanie zastosowany.

## Tryb awarii

Minos nigdy nie zgaduje werdyktu. Gdy go nie ma — brama odpowiedziała `nieocenione`,
werdykt nie nadszedł w wyznaczonym czasie albo brama odrzuciła żądanie z powodu
konfiguracji — decyduje tryb awarii:

- **fail-open** (domyślnie): post zostaje opublikowany, tak jak bez rozszerzenia. Nowa
  instalacja z błędem konfiguracji nie zamienia forum w forum moderowane ręcznie.
- **fail-closed**: post zostaje w kolejce do zatwierdzenia i czeka na moderatora. Zalecane
  dla forów dla młodzieży.

Gdy brama jest przeciążona (`429`) lub chwilowo niedostępna (`503`, brak połączenia),
rozszerzenie ponawia wysyłkę po czasie wskazanym przez bramę (albo po 1, 2, 4… minutach).
Gdy brama odrzuca żądanie z innego powodu (np. nieznany klucz, brak webhooka, niedozwolony
profil), ponawianie nie pomoże: posty obsługuje tryb awarii, a kod błędu trafia do
dziennika błędów phpBB i na stronę ustawień, z krótkim objaśnieniem.

## Co jest wysyłane, a co nigdy

Do bramy trafia wyłącznie:

- identyfikator `phpbb:<numer postu>` (po poprawce posta czekającego na werdykt:
  `phpbb:<numer>.<wersja>`) — bez treści;
- tekst postu: **pierwsze 3000 znaków**, bez cytatów (to cudze słowa, oceniane przy
  własnym poście) i bez formatowania BBCode/HTML;
- wybrany profil;
- liczba linków w poście i to, czy to pierwszy post autora (dla gości — nie).

**Nigdy** nie jest wysyłany e-mail, adres IP, identyfikator ani nazwa użytkownika. Klucz API
jest wysyłany tylko w nagłówku `X-Gateway-Key`, tylko przez `https://` i nigdy za
przekierowaniem.

## Których postów rozszerzenie nie wstrzymuje

- postów administratorów i moderatorów danego forum;
- postów, które phpBB i tak kieruje do kolejki (użytkownik bez uprawnienia „może pisać bez
  zatwierdzania”) — decyduje moderator, a tekst nie jest nigdzie wysyłany;
- postów, dla których inne rozszerzenie ustawiło już widoczność;
- postów bez tekstu do oceny (np. sam cytat);
- postów w forach spoza listy ustawień;
- wszystkich postów, gdy ocenianie jest wyłączone albo brakuje klucza, sekretu lub adresu.

## Wsparcie

Gdy brama zaznaczy `wsparcie` (treść może świadczyć o samookaleczeniu lub kryzysie autora),
rozszerzenie zapisuje to przy poście niezależnie od werdyktu: w dzienniku moderatorów, na
liście ostatnich postów w ustawieniach oraz w kolejce do zatwierdzenia w panelu moderatora
(oznaczenie przy temacie i opis nad treścią posta). To sygnał, by okazać wsparcie, a nie
podstawa do kary.

## Zadanie cron

Co 5 minut rozszerzenie stosuje tryb awarii do postów, na które werdykt nie nadszedł w
czasie, ponawia wysyłki i usuwa własne wpisy 30 dni po rozstrzygnięciu. Na forach o małym
ruchu warto uruchamiać cron phpBB z systemu (`bin/phpbbcli.php cron:run`), bo cron
wywoływany odsłonami stron może się opóźniać.

## Ograniczenia

- Oceniana jest treść postu, nie tytuł tematu.
- Poprawki postów już opublikowanych nie są oceniane ponownie.
- Publikacja z maskowaniem zastępuje tekst postu zwykłym tekstem (bez formatowania), a
  indeks wyszukiwarki phpBB zachowuje słowa z oryginału.
- Posty wstrzymane przez rozszerzenie zostają w kolejce, jeśli rozszerzenie zostanie
  wyłączone w menedżerze rozszerzeń albo usunięte — wtedy decyduje moderator.

## Licencja

GPL-2.0 (plik `LICENSE`). Dołączona biblioteka `minos-moderation/client-php` jest na
licencji MIT.
