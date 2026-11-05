# PHP Text Extractor

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/php-text-extractor/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/php-text-extractor/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/php-text-extractor.svg)](https://packagist.org/packages/intragile/php-text-extractor)
[![PHP](https://img.shields.io/packagist/php-v/intragile/php-text-extractor.svg)](https://packagist.org/packages/intragile/php-text-extractor)
[![License](https://img.shields.io/packagist/l/intragile/php-text-extractor.svg)](LICENSE)

**Szöveg kinyerése PDF-, Word-, Excel-, PowerPoint-, OpenDocument-, RTF-, HTML- és CSV-fájlokból,
valamint képekből** egyetlen hívással: keresőindexhez, RAG-pipeline-hoz, archiváláshoz.

[English README → README.md](README.md) ·
Laravel? → [intragile/laravel-text-extractor](https://github.com/intragile/laravel-text-extractor)

```php
use IntrAgile\TextExtractor\TextExtractor;

$result = TextExtractor::create()->extract('/path/to/feltoltes.docx');

if ($result->isSuccessful()) {
    $index->store($result->text);          // '' ha a fájlban egyszerűen nincs szöveg
} elseif ($result->isRetryable()) {
    $queue->retryLater();                  // timeout, hiányzó eszköz, IO-hiba
} else {
    $log->info($result->reason->value);    // encrypted, corrupt, too_large, ...
}
```

- **Nincs Composer-függőség.** A DOCX-, XLSX-, PPTX-, ODT-, ODS- és ODP-fájlokat a PHP saját
  `ZipArchive`- és `XMLReader`-osztálya olvassa. A PDF-hez a szabványos `pdftotext`, az OCR-hez a
  `tesseract` kell.
- **Korlátos memória.** Az Office-fájlokat a zipből streamelve, csomópontonként olvassa, soha nem
  tölti be egyben. A táblázatok szövegei és a kimenet felső határosak. A HTML-nek, amelyhez DOM
  kell, saját méretkorlátja van. A külső eszközök timeouttal és Linuxon folyamatonkénti memóriaplafonnal futnak, így
  egyetlen óriási szkennelt fájl sem dönti le a workert.
- **Az eredmény megkülönbözteti a „nincs szöveg” és a „hiba” esetet.** Egy szöveg nélküli fotó
  sikeres, üres eredmény, nem hiba. Egy timeout soha nem keveredik össze egy üres fájllal. A
  batch-feladat pontosan azokat a fájlokat próbálhatja újra, amelyeknél van értelme.
- **Biztonságos ismeretlen feltöltésekre.** A zip-bombát a ténylegesen kicsomagolt bájtok alapján
  állítja meg, nem a fájl által bevallott méret alapján. Külső DTD-t soha nem tölt be, a beágyazott
  DTD-t visszautasítja, így nincs XXE. A fájlnév soha nem jut el idézőjelezés nélkül egy shellhez.
- **Tartalom alapú felismerés.** A `jegyzet.txt` nevű PDF is PDF-ként kerül feldolgozásra.
- **PHP 8.1+, keretrendszertől független.**

## Telepítés

```bash
composer require intragile/php-text-extractor
```

Kötelező az `ext-mbstring`. Ezen felül azt kell telepíteni, amit a formátumaid igényelnek:

| Formátum | Mi kell hozzá |
|---|---|
| TXT, MD, CSV, TSV, JSON, RTF | semmi más |
| XML | `ext-xmlreader` |
| HTML | `ext-dom` |
| DOCX, XLSX, PPTX, ODT, ODS, ODP | `ext-zip`, `ext-xmlreader` |
| PDF | `pdftotext` (poppler-utils) |
| PNG, JPEG, TIFF, GIF, WebP, BMP | `tesseract` és a nyelvi adatai |

```bash
# Debian / Ubuntu (magyar OCR-rel)
apt-get install poppler-utils tesseract-ocr tesseract-ocr-hun tesseract-ocr-eng
# Alpine
apk add poppler-utils tesseract-ocr tesseract-ocr-data-hun tesseract-ocr-data-eng
# macOS
brew install poppler tesseract tesseract-lang
```

Hiányzó bővítmény vagy eszköz soha nem dob kivételt. Az adott formátumú fájlok ilyenkor `Failed`
státuszt kapnak, `missing_extension` vagy `missing_tool` okkal.

## Az eredmény

Az `extract()`, az `extractFromString()` és az `extractFromStream()` mindig
`IntrAgile\TextExtractor\Result`-ot ad vissza, és a bemenettel kapcsolatos hiba miatt soha nem dob
kivételt.

| Property | Típus | |
|---|---|---|
| `status` | `Status` | `Extracted`, `Empty`, `NotExtractable` vagy `Failed` |
| `text` | `string` | UTF-8, egységes sorvégek; `''`, ha a státusz nem `Extracted` |
| `format` | `?Format` | a felismert formátum, pl. `Format::Docx` |
| `reason` | `?Reason` | miért nem sima siker |
| `message` | `?string` | ember által olvasható részlet a naplóhoz |
| `truncated` | `bool` | a kimeneti limit elérve; a csonkolt eredmény mindig `Extracted` |

| Status | Jelentés | Újrapróbálás? |
|---|---|---|
| `Extracted` | van szöveg | – |
| `Empty` | sikeres feldolgozás, a fájlban nincs szöveg | nem |
| `NotExtractable` | a fájl ebben a formában soha nem dolgozható fel | nem |
| `Failed` | technikai hiba, a fájl később még sikerülhet | igen |

| Reason | Status | Mikor |
|---|---|---|
| `unreadable` | Failed | a fájl nem létezik vagy nem olvasható |
| `missing_extension` | Failed | nincs telepítve az `ext-zip`, `ext-xmlreader` vagy `ext-dom` |
| `missing_tool` | Failed | nincs telepítve a `pdftotext` vagy a `tesseract` |
| `tool_timeout` | Failed | az eszköz túllépte a timeoutot |
| `tool_failed` | Failed | az eszköz összeomlott, kifutott a memóriából vagy hibát jelzett |
| `internal_error` | Failed | egy extractor váratlan kivételt dobott (kérjük, jelezd) |
| `unsupported_format` | NotExtractable | ismeretlen formátum, régi `.doc`/`.xls`/`.ppt`, titkosított Office-fájl |
| `too_large` | NotExtractable | nagyobb a `maxInputBytes`-nál, vagy HTML és nagyobb a `maxHtmlBytes`-nál |
| `image_too_large` | NotExtractable | egy oldal vagy képkocka nagyobb a `maxImagePixels`-nél (az OCR indítása előtt ellenőrizve) |
| `encrypted` | NotExtractable | jelszóval védett PDF vagy OpenDocument |
| `corrupt` | NotExtractable | sérült vagy szerkezetileg hibás fájl, pl. egy `.pdf` nevű üres feltöltés |
| `archive_limit_exceeded` | NotExtractable | zip-bomba-korlát |
| `no_text_layer` | Empty | szövegréteg nélküli PDF, jellemzően szkennelt |

## Bemenet

```php
$extractor = TextExtractor::create();

$extractor->extract('/tmp/php3hX9a');                    // fájlútvonal
$extractor->extract('/tmp/php3hX9a', 'export.csv');      // + eredeti fájlnév a .csv/.md/.json/... miatt
$extractor->extractFromString($bytes, 'jegyzet.txt');
$extractor->extractFromStream($stream, 'riport.pdf');    // az aktuális pozíciótól olvas
```

A bináris formátumokat a tartalmuk alapján ismeri fel, így a `jegyzet.txt` nevű PDF is PDF. Szöveges
tartalomnál a fájlnév dönti el, hogy TXT, Markdown, CSV, TSV, JSON, XML vagy HTML. A hibás
feltöltést is a fájlnév alapján veszi észre: egy `.pdf`, `.docx`, `.png` stb. nevű üres vagy csak
szöveget tartalmazó fájl `NotExtractable/corrupt`, nem üres szövegfájl. `ext-zip` nélkül a fájlnévből
dönti el azt is, melyik Office-formátum a zip-konténer.

## Mit kapsz formátumonként

| Formátum | Kimenet |
|---|---|
| DOCX | törzs, táblázatok, szövegdobozok, láb- és végjegyzetek; a törölt változáskövetés és a mezőkódok kimaradnak |
| XLSX | minden munkalap `## Munkalap neve` sorral, utána a nem üres sorok, a cellák tabulátorral (a cellán belüli sortörésből szóköz lesz); a képletek tárolt értéke; a dátumok sorszámként maradnak |
| PPTX | diák a bemutató sorrendjében, üres sorral elválasztva; az előadói jegyzetek kimaradnak |
| ODT / ODS / ODP | mint a DOCX / XLSX / PPTX; a megjegyzések és a törölt változások kimaradnak |
| RTF | szöveg bekezdésekkel; a betűtábla, a képek és az egyéb metaadatok kimaradnak |
| HTML | a látható szöveg, a blokkelemek külön sorban; a script és a style kimarad; a `<head>` címe az első |
| XML | a szöveg- és CDATA-tartalom |
| CSV / TSV / TXT / MD / JSON | maga a szöveg, UTF-8-ra alakítva |
| PDF | a `pdftotext` kimenete, az oldalak között üres sor |
| Képek | Tesseract OCR a beállított nyelvekkel; a többoldalas TIFF minden oldala |

A DOCX, PPTX, ODT, ODP és HTML táblázatai úgy jönnek ki, mint a munkalapok sorai: soronként egy
sor, a cellák tabulátorral elválasztva, a cellán belüli bekezdések szóközzel összefűzve.

## Beállítások

```php
use IntrAgile\TextExtractor\Options;

$extractor = TextExtractor::create(new Options(
    ocrLanguages: 'hun+eng',
    fallbackEncoding: 'Windows-1250',
    processTimeout: 120,
));
```

| Option | Alapérték | |
|---|---|---|
| `maxInputBytes` | 100 MiB | a nagyobb fájl `too_large` |
| `maxOutputBytes` | 10 MiB | a hosszabb szöveget UTF-8-határon vágja, és beállítja a `truncated`-et |
| `maxArchiveEntryBytes` | 256 MiB | egy zip-bejegyzésből kicsomagolható bájtok |
| `maxArchiveTotalBytes` | 512 MiB | egy fájlból összesen kicsomagolható bájtok |
| `maxArchiveEntries` | 10 000 | ennyi bejegyzés lehet egy zip-konténerben |
| `maxHtmlBytes` | 20 MiB | a nagyobb HTML `too_large`; a DOM a méretének sokszorosát foglalja |
| `maxImagePixels` | 16 000 000 | ha egy oldal vagy képkocka nagyobb, OCR előtt visszautasítja |
| `processTimeout` | 60 s | külső eszközönként |
| `processMemoryMb` | 512 | eszközönkénti címtér-plafon Linuxon; `0` kikapcsolja |
| `pdftotextBinary` | `null` | útvonal vagy parancsnév; `null` esetén a `PATH`-ban keres |
| `tesseractBinary` | `null` | útvonal vagy parancsnév; `null` esetén a `PATH`-ban keres |
| `ocrLanguages` | `eng` | Tesseract-nyelvek `+`-szal; mindnek telepítve kell lennie |
| `fallbackEncoding` | `Windows-1252` | a nem érvényes UTF-8 szövegfájlokhoz; magyar exportokhoz `Windows-1250`. Bármely mbstring-kódolás, valamint a beépített Windows-1250, 1253, 1255–1258, CP874 és CP437 |

Az `$options->with(['maxOutputBytes' => 1_000_000])` egy módosított másolatot ad vissza.

## Saját extractor

Implementáld az `IntrAgile\TextExtractor\Contracts\Extractor` interfészt, és regisztráld. A
beépített extractorok előtt kerül sorra.

```php
use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Result;

final class CloudOcrExtractor implements Extractor
{
    public function supports(Format $format): bool
    {
        return $format->isImage();
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        return Result::extracted($this->client->recognise($path), $format);
    }
}

$extractor = TextExtractor::create()->withExtractor(new CloudOcrExtractor($client));
```

Nyers szöveget adj vissza: a normalizálást, a kimeneti limitet és az üres szöveg vizsgálatát a csomag
végzi. Sérült fájlnál dobj `IntrAgile\TextExtractor\Exception\CorruptInput` kivételt
(`NotExtractable/corrupt`); minden más kivételből `Failed/internal_error` lesz.

## Biztonság

A csomagot ismeretlen forrásból származó feltöltésekhez tervezték:

- **Zip-bomba.** Minden zip-bejegyzést számláló streamen át olvas. A kicsomagolás a
  `maxArchiveEntryBytes` / `maxArchiveTotalBytes` értéknél akkor is megáll, ha a fájl hazudik a
  méretéről. A `..`-ot tartalmazó vagy abszolút bejegyzésnevek soha nem kerülnek beolvasásra.
- **XML.** A hálózati hozzáférés, a DTD-betöltés és az entitásfeloldás ki van kapcsolva. Az olyan
  DOCTYPE-ot, amely csak egy külső DTD-t nevez meg (mint az Apple plist), elfogadja, mert azt a DTD-t
  soha nem tölti be. A beágyazott subsetet tartalmazó DOCTYPE-ot, ahol entitásokat lehetne deklarálni,
  `corrupt`-ként visszautasítja. Az útvonalak escape-elt `file://` URI-ként jutnak a libxml-hez, így a
  fájlnévben lévő `%41` vagy `php://` soha nem értelmeződik.
- **Külső eszközök.** A parancs argumentumtömbből épül, a bemeneti útvonal mindig abszolút, így soha
  nem olvasható opcióként. Amíg a memóriaplafon be van kapcsolva (alapértelmezés), az eszköz
  `/bin/sh -c 'ulimit -v …; exec …'` alakban indul, minden argumentum aposztrófok között, vagyis a
  shell csak a limitet állítja be; a kernel Linuxon érvényesíti. Windowson vagy
  `processMemoryMb: 0` mellett nincs shell. Minden futásnak van timeoutja, korlátos kimenete és saját,
  üres munkakönyvtára, amelyet utána töröl.
- **CPU.** A limitek a memóriát és a kimenetet korlátozzák, a PHP-n belüli CPU-időt nem. Egy kis zipből
  akár `maxArchiveTotalBytes` mennyiségű XML is feldolgozásra kerülhet, a libxml pedig négyzetes
  időben ellenőrzi az ismétlődő attribútumokat. Ismeretlen fájlokat queue workerben, job-timeouttal
  dolgozz fel.
- **Átmeneti fájlok** a `sys_get_temp_dir()`-ben jönnek létre, és `finally` blokkban törlődnek.

Sebezhetőség bejelentéséhez lásd: [SECURITY.md](SECURITY.md).

## Összehasonlítás

- **[spatie/pdf-to-text](https://github.com/spatie/pdf-to-text)**: csak PDF; hibánál kivételt dob,
  így az üres és a hibás fájlt a hívónak kell szétválasztania.
- **[vaites/php-apache-tika](https://github.com/vaites/php-apache-tika)**: több formátum, de Java-alapú
  Tika-szerver kell hozzá.
- **[phpoffice/phpword](https://github.com/PHPOffice/PHPWord) és társai**: teljes dokumentummodell
  olvasáshoz és íráshoz; az egész dokumentumot a memóriába töltik.

## Licenc

MIT, lásd [LICENSE](LICENSE).

---

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)
