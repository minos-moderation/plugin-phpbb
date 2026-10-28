# Minos — moderacja postów dla phpBB

Rozszerzenie phpBB 3.3, które przed publikacją wysyła nowe posty do bramy Wergiliusz
(usługa Minos) i stosuje werdykt, który brama odsyła podpisanym webhookiem: publikuje post,
publikuje go z zamaskowanymi fragmentami albo zostawia w kolejce do zatwierdzenia.

> **Stan:** trasa B2B bramy jest otwarta produkcyjnie od 25 października 2026 r. Klucz i
> sekret webhooka wydaje operator bramy. Bez klucza rozszerzenie można wypróbować z atrapą
> bramy (zob. `docs/development.md`).

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
włączysz. Włączone bez poprawnego adresu bramy, klucza lub sekretu wstrzymuje nowe posty i od
razu stosuje do nich tryb awarii, a każda strona panelu administracji mówi, czego brakuje.

Paczka zawiera tylko to, co ładuje phpBB, oraz źródła dołączonej biblioteki
`minos-moderation/client-php` (`vendor/minos-moderation/client-php/src/`). Nie zawiera
testów, atrapy bramy, `composer.lock` ani plików `composer.json` bibliotek; własny
`composer.json` rozszerzenia zostaje, bo bez niego phpBB nie zainstaluje rozszerzenia.

## Aktualizacja

Nową wersję instaluj zawsze w tej kolejności:

1. **Dostosuj → Zarządzaj rozszerzeniami → „Minos — moderacja postów” → Wyłącz** (nie „Usuń
   dane”: to usunęłoby zapisane werdykty, ustawienia, klucz i sekret).
2. Zastąp katalog `ext/minos/moderation/` zawartością nowej paczki (usuń stary katalog i
   rozpakuj nową paczkę na jego miejsce).
3. **Włącz** rozszerzenie: phpBB uruchamia wtedy migracje nowej wersji (nowe ustawienia i
   kolumny tabeli rozszerzenia). Ustawienia, klucz i sekret zostają.

Nie podmieniaj plików włączonego rozszerzenia: phpBB nie uruchomi wtedy migracji, a kod nowej
wersji odwoła się do kolumn, których nie ma w bazie (np. przy doręczeniu werdyktu na webhook),
i zakończy się błędem bazy danych. W czasie aktualizacji, gdy rozszerzenie jest wyłączone,
webhook odpowiada `404` (brama ponawia doręczenie), a wstrzymane posty czekają w kolejce; co
dzieje się z nimi po ponownym włączeniu — patrz „Wyłączenie”.

## Ustawienia

| Ustawienie | Domyślnie | Znaczenie |
|---|---|---|
| Oceniaj nowe posty | wyłączone | Wyłączone rozszerzenie nic nie robi — patrz „Wyłączenie”. |
| Adres bramy | `https://gateway.wergiliusz.app` | Tylko `https://`. `http://` jest dozwolone wyłącznie dla `localhost` / `127.0.0.1` (testy z atrapą bramy). |
| Klucz API | — | Klucz `wgb2b_…`. Strona pokazuje tylko jego początek; puste pole zachowuje zapisany klucz. |
| Sekret webhooka | — | Sekret podany raz, razem z kluczem. Strona pokazuje tylko jego początek; puste pole zachowuje zapisany sekret. Bez sekretu każde doręczenie jest odrzucane. |
| Profil oceny | `forum_adult` | `forum_adult` („Forum dla dorosłych”) albo `forum_teen` („Forum dla dzieci i młodzieży”, ocena surowsza). Profil musi być dozwolony dla klucza. |
| Tryb awarii | **fail-closed** | Co zrobić z postem bez werdyktu — patrz „Tryb awarii”. |
| Czas oczekiwania na werdykt | 20 minut | Od 20 do 1440 minut, liczone od chwili, gdy brama przyjęła post (odpowiedź `202`); dla postu, którego brama jeszcze nie przyjęła (np. była przeciążona) — od jego wstrzymania. Brama próbuje doręczyć werdykt przez 15 minut; po tym czasie (z zapasem) post obsługuje tryb awarii. |
| Werdykt „ocenzurowane” | publikuj z maskowaniem | Publikacja tekstu z zamaskowanymi fragmentami albo pozostawienie w kolejce. |
| Werdykt „zablokowane” | zostaw w kolejce | Pozostawienie w kolejce albo miękkie usunięcie (moderator może post przywrócić). |
| Fora | wszystkie | Fora, w których nowe posty są oceniane. |
| Grupy użytkowników | wszystkie | Oceniane są posty członków zaznaczonych grup (wystarczy jedna z nich); posty pozostałych użytkowników publikują się jak dawniej i nic o nich nie trafia do bramy. Liczy się tylko przyjęte członkostwo (nie prośba o dołączenie); gość należy do grupy gości. Jeśli żadna z zaznaczonych grup już nie istnieje, oceniane są posty wszystkich. Wybieraj grupy, z których użytkownik nie wystąpi sam (specjalne grupy phpBB, zamknięte, ukryte): z grupy otwartej członek może wystąpić w swoim panelu i od tej chwili pisać bez oceny — lista w ustawieniach oznacza takie grupy. |

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

Gdy webhook odrzuca doręczenia z nieprawidłowym podpisem, strona ustawień mówi, ile ich było
od ostatniego przyjętego doręczenia, kiedy przyszło ostatnie i dlaczego zostało odrzucone:
podpis nie pasuje do zapisanego sekretu (sprawdź sekret), czas podpisu różni się od zegara
serwera forum o ponad 5 minut (sprawdź zegar serwera) albo doręczenie nie miało poprawnego
nagłówka podpisu (np. usuwa go serwer pośredniczący). Dzięki temu zły sekret albo
rozregulowany zegar widać bez przeglądania dzienników. Brama zawsze dostaje tę samą odpowiedź
`401`, bez powodu; sekretu, nagłówka ani treści doręczenia rozszerzenie nigdzie nie zapisuje.
Komunikat znika po pierwszym doręczeniu z poprawnym podpisem albo po zapisaniu nowego
sekretu. Doręczenie z nieprawidłowym podpisem może wysłać każdy, kto zna adres webhooka, więc
pojedynczy komunikat nie musi oznaczać błędu ustawień.

## Jak to działa

1. Użytkownik wysyła nowy temat lub odpowiedź. Rozszerzenie kieruje post do kolejki do
   zatwierdzenia (autor widzi zwykły komunikat phpBB o poście czekającym na akceptację) i
   od razu wysyła go do bramy — chyba że trwa przerwa w wysyłaniu po odmowie bramy (patrz
   „Tryb awarii”): wtedy post wyśle zadanie cron. Autor czeka na odpowiedź bramy najwyżej
   10 sekund; jeśli jej nie ma, post czeka w kolejce na ponowienie.
2. Brama przyjmuje post (`202`) i ocenia go w tle — dla kluczy darmowych nawet do 15 minut.
3. Werdykt przychodzi na adres webhooka, podpisany sekretem. Rozszerzenie sprawdza podpis
   (niepodpisane, sfałszowane i przeterminowane doręczenia są odrzucane), odpowiada od razu
   i dopiero potem, w tym samym żądaniu webhooka, stosuje werdykt (na serwerze z PHP-FPM
   brama ma wtedy już odpowiedź). Publikacja wysyła przy tym zwykłe powiadomienia phpBB;
   e-maile z nimi phpBB domyślnie odkłada do swojej kolejki e-maili, a gdy kolejka jest
   wyłączona (rozmiar paczki e-maili 0), wysyła je w tym żądaniu:

   | Werdykt | Post do 3000 znaków | Post dłuższy (oceniony tylko początek) |
   |---|---|---|
   | `bezpieczne` | publikacja | tryb awarii, jak przy `nieocenione` |
   | `ocenzurowane` | publikacja tekstu z zamaskowanymi fragmentami (bez formatowania; oryginał zostaje w tabeli rozszerzenia przez 30 dni, potem jest usuwany) albo kolejka — wg ustawienia; bez zamaskowanego tekstu zawsze kolejka | zawsze kolejka |
   | `zablokowane` | kolejka albo miękkie usunięcie — wg ustawienia | tak samo |
   | `nieocenione` | tryb awarii | tryb awarii |

   Zamaskowany tekst zastępuje treść postu tylko wtedy, gdy ma dokładnie tyle znaków co
   tekst wysłany do bramy i różni się od niego wyłącznie znakami `█`, tytuł i ankieta
   wysłane razem z postem zostały nietknięte (rozszerzenie nigdy nie zmienia tytułu ani
   ankiety), a wysłany tekst jest
   dokładnie tym, co napisał autor (post bez formatowania BBCode, cytatów i atrybutów —
   inaczej publikacja zmieniłaby więcej niż zamaskowane słowa). W każdym innym wypadku post
   zostaje w kolejce z notatką „zamaskowany tekst wymaga ręcznego zastosowania”, a
   zamaskowany tekst widać w panelu moderatora i na liście w ustawieniach rozszerzenia.
   Zamaskowany tekst jest publikowany dopiero po sprawdzeniu, że rzeczywiście zapisał się w
   bazie. Jeśli zapis się nie uda, post zostaje w kolejce — oryginalny tekst nigdy nie
   zostaje opublikowany zamiast zamaskowanego.
4. Każda decyzja rozszerzenia trafia do dziennika moderatorów (autor wpisu: Anonymous, opis:
   „Minos …”, z tematem postu; treści postu tam nie ma). Te wpisy należą do forum: nie
   znikają po 30 dniach razem z danymi rozszerzenia (patrz „Zadanie cron”), tylko zostają w
   dzienniku jak każdy inny jego wpis, według zasad forum. Powiadomienia o nowym poście (dla obserwujących temat i forum, zakładki,
   cytowanych) wychodzą raz, w chwili publikacji przez rozszerzenie; jeśli post zatwierdził
   moderator, powiadamia wyłącznie phpBB.

   Powiadomienie phpBB „post/temat czeka na zatwierdzenie” o poście, który rozszerzenie
   wstrzymało do oceny, pojawia się od razu tylko na liście powiadomień na forum: e-mail z
   nim (i inne wiadomości) rozszerzenie wstrzymuje. Gdy rozszerzenie opublikuje post,
   powiadomienie znika z listy, a e-mail nie wychodzi wcale. Gdy zostawi post w kolejce dla
   moderatora (werdykt, tryb awarii fail-closed, brak tekstu do oceny, post zmieniony w
   czasie oceny), moderatorzy dostają to powiadomienie jeszcze raz, tym razem także e-mailem
   (jeśli mają je włączone) — jeden raz na post: gdy post wróci do kolejki ponownie (np.
   spóźniony werdykt, poprawka), powiadomienie zostaje na liście, a drugi e-mail nie wychodzi. O postach, których rozszerzenie nie wstrzymuje,
   phpBB powiadamia jak zwykle. Jeśli wyłączysz ocenianie w ustawieniach, gdy posty czekają
   na werdykt, moderatorzy dostają wtedy e-mail o każdym z nich (od tej chwili decydują o
   nich sami). Po wyłączeniu rozszerzenia w menedżerze rozszerzeń e-mail o nich nie wyjdzie:
   posty widać w kolejce i na liście powiadomień.

Ten sam werdykt doręczony dwa razy jest stosowany raz. Jeśli moderator zatwierdził lub
usunął post, zanim przyszedł werdykt, decyzja moderatora zostaje, a werdykt jest pomijany.
Jeśli autor albo moderator poprawi post, który czeka na werdykt (także sam tytuł tematu albo
ankietę), post zostaje w kolejce i jest oceniany od nowa jako `phpbb:<numer>.<wersja>`;
werdykt dla poprzedniej wersji nie zostanie zastosowany.

## Tryb awarii

Minos nigdy nie zgaduje werdyktu. Gdy go nie ma — brama odpowiedziała `nieocenione`,
werdykt nie nadszedł w wyznaczonym czasie, brama odrzuciła żądanie z powodu konfiguracji,
oceniła tylko początek długiego postu, post nie ma tekstu do oceny (np. sam obrazek,
załącznik, link bez widocznego opisu albo sam tytuł) albo ocenianie jest włączone, ale brakuje klucza, sekretu lub poprawnego adresu bramy — decyduje
tryb awarii:

- **fail-closed** (domyślnie): post zostaje w kolejce do zatwierdzenia i czeka na
  moderatora; w panelu moderatora widać, dlaczego.
- **fail-open**: post zostaje opublikowany. Taka publikacja jest oznaczona (w dzienniku
  moderatorów i na liście w ustawieniach jako „opublikowany bez oceny”). Jeśli werdykt
  przyjdzie później, rozszerzenie go zastosuje: `zablokowane` cofa post do kolejki (albo
  usuwa go miękko, zgodnie z ustawieniem), `ocenzurowane` maskuje go albo cofa do kolejki,
  `bezpieczne` potwierdza publikację. Jeśli jednak moderator w międzyczasie coś z postem
  zrobił (usunął, przywrócił, zatwierdził), zostaje decyzja człowieka.

Post zostawiony w kolejce przez tryb fail-closed również przyjmie spóźniony werdykt, o ile
moderator nie zdążył go rozpatrzyć.

Poprawka postu, na który werdykt nie nadszedł w czasie oczekiwania — opublikowanego przez
tryb fail-open albo zostawionego w kolejce przez fail-closed — cofa go do kolejki do
zatwierdzenia (także poprawka moderatora), a rozszerzenie ocenia go od nowa jako
`phpbb:<numer>.<wersja>`; spóźniony werdykt dla poprzedniej wersji nie jest już stosowany.
Poprawka postu opublikowanego bez oceny po odpowiedzi `nieocenione` albo po odmowie bramy
nie jest oceniana (patrz „Ograniczenia”).

Gdy brama odmawia z powodu limitów (`429`: limit klucza na minutę, na dobę lub zapytań w
toku, wspólny limit wszystkich kluczy, pełna kolejka) albo jest chwilowo niedostępna (`503`,
`408`, brak połączenia), a także gdy pod adresem bramy odpowiada coś innego niż brama
(odpowiedź `2xx` inna niż `202`, np. strona logowania serwera pośredniczącego — wtedy
komunikat radzi sprawdzić adres bramy), rozszerzenie przestaje wysyłać posty do czasu
wskazanego przez bramę (bez takiego wskazania — na minutę). W tym czasie nowe posty czekają w kolejce i wyśle je zadanie
cron, a zadanie cron po pierwszej takiej odpowiedzi nie wysyła kolejnych paczek. Każda
odmowa z powodu limitów klucza zużywa jego pulę zapytań, więc zamiast odmowy dla każdego
postu jest jedna. Post, którego dotyczyła odmowa, jest ponawiany po czasie wskazanym przez
bramę (albo po 1, 2, 4… minutach). Jeśli przerwa kończy się później niż czas oczekiwania na
werdykt (np. po wyczerpaniu dziennego limitu klucza — do końca doby), post od razu obsługuje
tryb awarii, bez wysyłania. Przerwę widać na stronie ustawień jako komunikat z powodem i
godziną, do której rozszerzenie nie wysyła postów; znika po pierwszym przyjętym poście.
Zapisanie ustawień kończy przerwę: po zmianie klucza (np. na płatny) albo adresu bramy
zapisz ustawienia, aby spróbować od razu — następny post zostanie wysłany (jeśli brama nadal
odmawia, przerwa wraca po tej jednej odmowie).
Gdy brama odrzuca żądanie z innego powodu (np. nieznany klucz, brak webhooka, niedozwolony
profil), ponawianie nie pomoże: posty obsługuje tryb awarii, a kod błędu trafia do
dziennika błędów phpBB i na stronę ustawień, z krótkim objaśnieniem.

Dokładnie: post przyjmuje wyłącznie odpowiedź `202`, i to tylko wtedy, gdy wymienia go w
polu `przyjete` (post, którego tam brak, jest ponawiany). Ponawiane są też odpowiedź `429` (z
każdym kodem), `408` (liczona jak brak odpowiedzi), każda odpowiedź `5xx`, brak odpowiedzi
(błąd połączenia albo przekroczone 10 sekund) i każda odpowiedź `2xx` inna niż `202` —
nawet taka, która wymienia post w polu `przyjete` — bo to nie jest przyjęcie przez bramę.
Każda inna odpowiedź — `3xx` (np. przekierowanie) i `4xx` inne niż `408` i `429` — jest
traktowana jak błąd konfiguracji.
Gdy błąd dotyczy jednego postu z paczki (pole `element`), tryb awarii obsługuje tylko ten
post, a pozostałe są wysyłane ponownie bez niego.

## Kopia testowa forum

Nie wpisuj klucza i sekretu produkcyjnego forum w jego kopii testowej (np. na serwerze
deweloperskim). Brama odsyła werdykty na adres webhooka zapisany przy kluczu, czyli na forum
produkcyjne, a kopia ma te same numery postów: werdykt dla postu z kopii może trafić na
czekający post o tym samym numerze i przerwać jego ocenę (post zostaje wtedy w kolejce dla
moderatora), a każde zapytanie kopii zużywa limity klucza produkcyjnego. Poproś operatora o
osobny klucz (i sekret) dla kopii testowej.

## Co jest wysyłane, a co nigdy

Do bramy trafia wyłącznie:

- identyfikator `phpbb:<numer postu>` (po poprawce posta czekającego na werdykt:
  `phpbb:<numer>.<wersja>`) — bez treści;
- tekst postu: **pierwsze 3000 znaków**, razem z cytatami — każdy poprzedzony wierszem
  „Kasia napisał(a):” — bo oceniane jest wszystko, co post publikuje, a słowa ujęte w
  `[quote]` nie mogą ominąć moderacji; bez formatowania BBCode/HTML, ale z tekstem atrybutów
  `title` i `alt` (tam też można ukryć słowa), wysyłanym tylko obok innego tekstu. Adres
  linku widoczny dla czytelnika (wklejony adres, `[url]adres[/url]`) jest tekstem i trafia do
  bramy; adres obrazka (`[img]`), nazwa pliku wstawionego załącznika i adres filmu
  (`[flash]`) nie — czytelnik widzi obrazek, plik albo film, a brama nie ocenia obrazów. Pierwszy post nowego tematu jest poprzedzony
  tytułem tematu i pustym wierszem, a jeśli temat ma ankietę — także jej pytaniem i
  opcjami (każda w osobnym wierszu) i pustym wierszem (tytuł i ankieta mieszczą się w tych
  3000 znakach); odpowiedź — własnym tematem, jeśli autor zmienił domyślny „Re: …”. Sam
  tytuł, bez tekstu postu ani ankiety, nie jest wysyłany: taki post obsługuje od razu tryb
  awarii (patrz niżej);
- wybrany profil;
- liczba linków w poście, do 10 domen, na które prowadzą (np. `example.com`), i to, czy to
  pierwszy post autora (dla gości — nie).

**Nigdy** nie są wysyłane dane konta autora: jego adres e-mail, adres IP, identyfikator ani
login. Jedyne imiona i nazwy, które opuszczają forum, to te, które autor sam wpisał w treść
postu — przede wszystkim autorzy cytatów (`[quote="Kasia"]` trafia do bramy jako „Kasia
napisał(a):”) — bo brama ocenia je jako część tekstu, tak jak każde inne słowo postu.

Klucz API jest wysyłany tylko w nagłówku `X-Gateway-Key`, tylko przez `https://` i nigdy za
przekierowaniem.

## Których postów rozszerzenie nie wstrzymuje

- postów administratorów i moderatorów danego forum;
- postów, które phpBB i tak kieruje do kolejki do zatwierdzenia (użytkownik nie ma w danym
  forum uprawnienia „może pisać bez zatwierdzania”): rozpatruje je moderator w kolejce phpBB
  **bez werdyktu Minosa**, a ich tekst nie jest nigdzie wysyłany. W domyślnej konfiguracji
  phpBB 3.3 dotyczy to nowo zarejestrowanych użytkowników: konto, które ma mniej postów niż
  limit phpBB dla nowych użytkowników (domyślnie 3), należy do grupy nowo zarejestrowanych
  (`NEWLY_REGISTERED`), a ta w forum utworzonym przy instalacji ma rolę „nowy użytkownik”,
  która nie pozwala pisać bez zatwierdzania (sprawdzone w źródle phpBB 3.3; nazwy w panelu
  zależą od pakietu językowego). To zwykle pierwsze posty nowych kont, w tym spam:
  moderator ocenia je bez pomocy Minosa;
- postów użytkowników spoza grup wybranych w ustawieniu „Grupy użytkowników”;
- postów, dla których inne rozszerzenie ustawiło już widoczność;
- postów w forach spoza listy ustawień;
- wszystkich postów, gdy ocenianie jest wyłączone (gdy jest włączone, ale brakuje klucza,
  sekretu lub poprawnego adresu bramy, nowe posty są wstrzymywane i od razu obsługuje je tryb
  awarii).

Post bez tekstu do oceny jest wstrzymywany jak każdy inny i od razu obsługuje go tryb awarii,
bez wysyłania czegokolwiek do bramy; w panelu moderatora ma notatkę „brak tekstu do oceny”.
Tak jest z postem, którego czytelnik widzi tylko obrazek (`[img]`, także `[img=…]`, nawet z
podpisem), załącznik, film albo link bez widocznego opisu (`[url=…] [/url]`), z samym
formatowaniem wokół spacji (np. `[b] [/b]`) i z nowym tematem albo odpowiedzią z własnym
tematem, w których poza tytułem nie ma tekstu: sam tytuł nigdy nie jest wysyłany. Sam cytat
jest zwykłym tekstem: post zostaje wysłany i oceniony.

## Czas do werdyktu i liczba werdyktów

Strona ustawień pokazuje dla ostatnich 7 dni medianę (P50) i 95. percentyl (P95) czasu od
przyjęcia postu przez bramę (odpowiedź `202`) do doręczenia werdyktu na webhook, liczbę
werdyktów `bezpieczne`, `ocenzurowane`, `zablokowane` i `nieocenione` oraz liczbę postów, na
które werdykt nie nadszedł w czasie oczekiwania. Liczby powstają z dwóch chwil zapisanych przy
każdym poście w tabeli rozszerzenia (przyjęcie i doręczenie); nie zawierają treści postów ani
klucza i nie wymagają żadnych dodatkowych zapytań do bramy. Po poprawce postu czekającego na
werdykt liczy się tylko ocena nowej wersji.

## Wsparcie

Gdy brama zaznaczy `wsparcie` (treść może świadczyć o samookaleczeniu lub kryzysie autora),
rozszerzenie zapisuje to przy poście niezależnie od werdyktu: w dzienniku moderatorów, na
liście ostatnich postów w ustawieniach oraz w kolejce do zatwierdzenia w panelu moderatora
(oznaczenie przy temacie i opis nad treścią posta). To sygnał, by okazać wsparcie, a nie
podstawa do kary.

## Zadanie cron

Co 5 minut rozszerzenie stosuje tryb awarii do postów, na które werdykt nie nadszedł w
czasie, wysyła posty, które na to czekają (paczkami do 20; po pierwszej odpowiedzi bramy
„poczekaj” — `429`, `503`, brak połączenia — nie wysyła kolejnych paczek), i usuwa własne
wpisy 30 dni po rozstrzygnięciu: z wpisem znikają zapisany werdykt i oryginał zamaskowanego
postu (to wszystkie dane, które rozszerzenie przechowuje o poście). Wpisy w dzienniku
moderatorów phpBB (temat postu, decyzja rozszerzenia, sygnał `wsparcie`) zostają: to rejestr
forum, przechowywany według zasad forum. Na forach o małym
ruchu warto uruchamiać cron phpBB z systemu (`bin/phpbbcli.php cron:run`), bo cron
wywoływany odsłonami stron może się opóźniać.

## Wyłączenie

Wyłączone ocenianie oznacza, że rozszerzenie nic nie robi: nowe posty publikują się jak
dawniej, nic nie jest wysyłane do bramy, webhook odpowiada `404` (brama ponawia doręczenie
do końca swojego czasu), a zadanie cron nie działa — bez ponowień i bez trybu awarii. Posty,
które czekały na werdykt, zostają w kolejce do zatwierdzenia i rozpatruje je moderator. Po
ponownym włączeniu oceniania w ustawieniach posty, które wciąż czekały na werdykt, są
wysyłane do oceny od nowa, z nowym czasem oczekiwania (nie trafiają od razu do trybu awarii).

To samo dotyczy wyłączenia albo usunięcia rozszerzenia w menedżerze rozszerzeń. Po ponownym
włączeniu rozszerzenia w menedżerze (bez zmiany ustawienia) zadanie cron wraca do czekających
postów tam, gdzie skończyło: te, których czas oczekiwania minął, obsługuje tryb awarii. Aby
wysłać je od nowa, wyłącz i włącz ocenianie w ustawieniach.

## Usunięcie rozszerzenia

Wyłączenie rozszerzenia w menedżerze rozszerzeń (**Wyłącz**) zostawia jego dane: patrz
„Wyłączenie”. **Usuń dane** w menedżerze rozszerzeń cofa migracje rozszerzenia: usuwa jego
tabelę (`minos_pending` — z zapisanymi werdyktami i oryginałami zamaskowanych postów), jego
ustawienia (także klucz i sekret webhooka) i stronę w panelu administracji. Posty, które
czekały w kolejce, zostają w kolejce phpBB dla moderatorów; posty opublikowane z maskowaniem
zostają zamaskowane, a ich oryginałów nie da się już odzyskać z rozszerzenia. Wpisy w
dzienniku moderatorów zostają. Na żywym forum phpBB nie zostało to jeszcze sprawdzone
testem (opis wynika z migracji rozszerzenia i z kodu phpBB 3.3).

## Ograniczenia

- Poprawki postów opublikowanych po werdykcie (`bezpieczne`, publikacja z maskowaniem)
  albo bez oceny po odpowiedzi `nieocenione` lub odmowie bramy nie są oceniane ponownie.
  Od nowa oceniana jest tylko poprawka postu, który czeka na werdykt albo na który werdykt
  nie nadszedł w czasie oczekiwania (patrz „Tryb awarii”).
- Publikacja z maskowaniem zastępuje tekst postu zwykłym tekstem (bez formatowania), a
  indeks wyszukiwarki phpBB zachowuje słowa z oryginału.
- Domeny linków są ustalane w przybliżeniu (bez pełnej listy sufiksów publicznych).

## Licencja

GPL-2.0 (plik `LICENSE`). Dołączona biblioteka `minos-moderation/client-php` jest na
licencji MIT.
