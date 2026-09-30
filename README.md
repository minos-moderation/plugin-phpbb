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

Po instalacji ocenianie jest wyłączone: rozszerzenie niczego nie wstrzymuje, dopóki go nie
włączysz i nie podasz adresu bramy, klucza oraz sekretu.

Paczka zawiera tylko to, co ładuje phpBB, oraz źródła dołączonej biblioteki
`minos-moderation/client-php` (`vendor/minos-moderation/client-php/src/`). Nie zawiera
testów, atrapy bramy, `composer.lock` ani plików `composer.json` bibliotek; własny
`composer.json` rozszerzenia zostaje, bo bez niego phpBB nie zainstaluje rozszerzenia.

## Ustawienia

| Ustawienie | Domyślnie | Znaczenie |
|---|---|---|
| Oceniaj nowe posty | wyłączone | Wyłączone rozszerzenie nic nie robi — patrz „Wyłączenie”. |
| Adres bramy | `https://gateway.wergiliusz.app` | Tylko `https://`. `http://` jest dozwolone wyłącznie dla `localhost` / `127.0.0.1` (testy z atrapą bramy). |
| Klucz API | — | Klucz `wgb2b_…`. Strona pokazuje tylko jego początek; puste pole zachowuje zapisany klucz. |
| Sekret webhooka | — | Sekret podany raz, razem z kluczem. Strona pokazuje tylko jego początek; puste pole zachowuje zapisany sekret. Bez sekretu każde doręczenie jest odrzucane. |
| Profil oceny | `forum_adult` | `forum_adult` (forum dla dorosłych) albo `forum_teen` (forum dla młodzieży, ocena surowsza). Profil musi być dozwolony dla klucza. |
| Tryb awarii | **fail-closed** | Co zrobić z postem bez werdyktu — patrz „Tryb awarii”. |
| Czas oczekiwania na werdykt | 20 minut | Od 20 do 1440 minut. Brama próbuje doręczyć werdykt przez 15 minut; po tym czasie (z zapasem) post obsługuje tryb awarii. |
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

   | Werdykt | Post do 3000 znaków | Post dłuższy (oceniony tylko początek) |
   |---|---|---|
   | `bezpieczne` | publikacja | tryb awarii, jak przy `nieocenione` |
   | `ocenzurowane` | publikacja tekstu z zamaskowanymi fragmentami (bez formatowania; oryginał zostaje w tabeli rozszerzenia) albo kolejka — wg ustawienia; bez zamaskowanego tekstu zawsze kolejka | zawsze kolejka |
   | `zablokowane` | kolejka albo miękkie usunięcie — wg ustawienia | tak samo |
   | `nieocenione` | tryb awarii | tryb awarii |

   Zamaskowany tekst jest publikowany dopiero po sprawdzeniu, że rzeczywiście zapisał się w
   bazie. Jeśli zapis się nie uda, post zostaje w kolejce — oryginalny tekst nigdy nie
   zostaje opublikowany zamiast zamaskowanego.
4. Każda decyzja rozszerzenia trafia do dziennika moderatorów (autor wpisu: Anonymous, opis:
   „Minos …”). Powiadomienia o nowym poście (dla obserwujących temat i forum, zakładki,
   cytowanych) wychodzą raz, w chwili publikacji przez rozszerzenie; jeśli post zatwierdził
   moderator, powiadamia wyłącznie phpBB.

   Uwaga: dla każdego wstrzymanego postu phpBB od razu, jeszcze przed werdyktem, wysyła
   moderatorom swoje zwykłe powiadomienie (także e-mailem, jeśli mają je włączone), że post
   lub temat czeka w kolejce. Gdy rozszerzenie opublikuje post, to powiadomienie zostaje
   wycofane z listy powiadomień, ale wysłanego e-maila nie da się cofnąć. Moderatorzy, którym
   to przeszkadza, mogą wyłączyć e-maile „post/temat czeka na zatwierdzenie” w swoim panelu.

Ten sam werdykt doręczony dwa razy jest stosowany raz. Jeśli moderator zatwierdził lub
usunął post, zanim przyszedł werdykt, decyzja moderatora zostaje, a werdykt jest pomijany.
Jeśli autor poprawi post, który czeka na werdykt, post zostaje w kolejce i jest oceniany od
nowa; werdykt dla poprzedniej wersji nie zostanie zastosowany.

## Tryb awarii

Minos nigdy nie zgaduje werdyktu. Gdy go nie ma — brama odpowiedziała `nieocenione`,
werdykt nie nadszedł w wyznaczonym czasie, brama odrzuciła żądanie z powodu konfiguracji
albo oceniła tylko początek długiego postu — decyduje tryb awarii:

- **fail-closed** (domyślnie): post zostaje w kolejce do zatwierdzenia i czeka na
  moderatora; w panelu moderatora widać, dlaczego.
- **fail-open**: post zostaje opublikowany. Taka publikacja jest oznaczona (w dzienniku
  moderatorów i na liście w ustawieniach jako „opublikowany bez oceny”). Jeśli werdykt
  przyjdzie później, rozszerzenie go zastosuje: `zablokowane` cofa post do kolejki (albo
  usuwa go miękko, zgodnie z ustawieniem), `ocenzurowane` maskuje go albo cofa do kolejki,
  `bezpieczne` potwierdza publikację. Jeśli jednak moderator w międzyczasie coś z postem
  zrobił (usunął, przywrócił, zatwierdził) albo post został poprawiony, zostaje decyzja
  człowieka.

Post zostawiony w kolejce przez tryb fail-closed również przyjmie spóźniony werdykt, o ile
moderator nie zdążył go rozpatrzyć.

Gdy brama jest przeciążona (`429`) lub chwilowo niedostępna (`503`, brak połączenia),
rozszerzenie ponawia wysyłkę po czasie wskazanym przez bramę (albo po 1, 2, 4… minutach).
Gdy brama odrzuca żądanie z innego powodu (np. nieznany klucz, brak webhooka, niedozwolony
profil), ponawianie nie pomoże: posty obsługuje tryb awarii, a kod błędu trafia do
dziennika błędów phpBB i na stronę ustawień, z krótkim objaśnieniem.

## Co jest wysyłane, a co nigdy

Do bramy trafia wyłącznie:

- identyfikator `phpbb:<numer postu>` (po poprawce posta czekającego na werdykt:
  `phpbb:<numer>.<wersja>`) — bez treści;
- tekst postu: **pierwsze 3000 znaków**, razem z cytatami — każdy poprzedzony wierszem
  „Kasia napisał(a):” — bo oceniane jest wszystko, co post publikuje, a słowa ujęte w
  `[quote]` nie mogą ominąć moderacji; bez formatowania BBCode/HTML, ale z tekstem atrybutów
  `title` i `alt` (tam też można ukryć słowa);
- wybrany profil;
- liczba linków w poście, do 10 domen, na które prowadzą (np. `example.com`), i to, czy to
  pierwszy post autora (dla gości — nie).

**Nigdy** nie jest wysyłany e-mail, adres IP, identyfikator ani nazwa użytkownika. Klucz API
jest wysyłany tylko w nagłówku `X-Gateway-Key`, tylko przez `https://` i nigdy za
przekierowaniem.

## Których postów rozszerzenie nie wstrzymuje

- postów administratorów i moderatorów danego forum;
- postów w forach, które i tak wymagają zatwierdzania (użytkownik bez uprawnienia „może
  pisać bez zatwierdzania”): takie posty rozpatruje moderator, a ich tekst nie jest nigdzie
  wysyłany, bo werdykt niczego by nie zmienił;
- postów, dla których inne rozszerzenie ustawiło już widoczność;
- postów bez żadnego tekstu do oceny (sam cytat jest zwykłym postem: zostaje wstrzymany i
  oceniony);
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

## Wyłączenie

Wyłączone ocenianie oznacza, że rozszerzenie nic nie robi: nowe posty publikują się jak
dawniej, nic nie jest wysyłane do bramy, webhook odpowiada `404` (brama ponawia doręczenie
do końca swojego czasu), a zadanie cron nie działa — bez ponowień i bez trybu awarii. Posty,
które czekały na werdykt, zostają w kolejce do zatwierdzenia i rozpatruje je moderator. Po
ponownym włączeniu rozszerzenie wraca do czekających postów.

To samo dotyczy wyłączenia albo usunięcia rozszerzenia w menedżerze rozszerzeń.

## Ograniczenia

- Oceniana jest treść postu, nie tytuł tematu.
- Poprawki postów już opublikowanych nie są oceniane ponownie.
- Publikacja z maskowaniem zastępuje tekst postu zwykłym tekstem (bez formatowania), a
  indeks wyszukiwarki phpBB zachowuje słowa z oryginału.
- Domeny linków są ustalane w przybliżeniu (bez pełnej listy sufiksów publicznych).

## Licencja

GPL-2.0 (plik `LICENSE`). Dołączona biblioteka `minos-moderation/client-php` jest na
licencji MIT.
