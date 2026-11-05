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
- **Korlátos memória.** Az Office-fájlokat streamelve olvassa, soha nem tölti be egyben. A kimenet
  felső határos. A külső eszközök timeouttal és Linuxon folyamatonkénti memóriaplafonnal futnak, így
  egyetlen óriási szkennelt fájl sem dönti le a workert.
- **Az eredmény megkülönbözteti a „nincs szöveg” és a „hiba” esetet.** Egy szöveg nélküli fotó
  sikeres, üres eredmény, nem hiba. Egy timeout soha nem keveredik össze egy üres fájllal. A
  batch-feladat pontosan azokat a fájlokat próbálhatja újra, amelyeknél van értelme.
- **Biztonságos ismeretlen feltöltésekre.** A zip-bombát a ténylegesen kicsomagolt bájtok alapján
  állítja meg, nem a fájl által bevallott méret alapján. A DOCTYPE-ot tartalmazó XML-t visszautasítja,
  így nincs XXE. A külső eszközök shell nélkül indulnak.
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
| `truncated` | `bool` | a kimeneti limit elérve |

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
| `too_large` | NotExtractable | nagyobb a `maxInputBytes`-nál |
| `image_too_large` | NotExtractable | nagyobb a `maxImagePixels`-nél (az OCR indítása előtt ellenőrizve) |
| `encrypted` | NotExtractable | jelszóval védett PDF vagy OpenDocument |
| `corrupt` | NotExtractable | sérült vagy szerkezetileg hibás fájl |
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

A fájlnév csak a szöveges fájloknál számít: ott dönti el, hogy TXT, Markdown, CSV, TSV, JSON, XML
vagy HTML. A bináris formátumokat a tartalmuk alapján ismeri fel.

## Mit kapsz formátumonként

| Formátum | Kimenet |
|---|---|
| DOCX | törzs, táblázatok, szövegdobozok, láb- és végjegyzetek; a törölt változáskövetés és a mezőkódok kimaradnak |
| XLSX | minden munkalap `## Munkalap neve` sorral, utána a nem üres sorok, a cellák tabulátorral; a képletek tárolt értéke; a dátumok sorszámként maradnak |
| PPTX | diák a bemutató sorrendjében, üres sorral elválasztva; az előadói jegyzetek kimaradnak |
| ODT / ODS / ODP | mint a DOCX / XLSX / PPTX; a megjegyzések és a törölt változások kimaradnak |
| RTF | szöveg bekezdésekkel; a betűtábla, a képek és az egyéb metaadatok kimaradnak |
| HTML | a látható szöveg, a blokkelemek külön sorban; a script és a style kimarad; a `<title>` az első |
| XML | a szöveg- és CDATA-tartalom |
| CSV / TSV / TXT / MD / JSON | maga a szöveg, UTF-8-ra alakítva |
| PDF | a `pdftotext` kimenete, az oldalak között üres sor |
| Képek | Tesseract OCR a beállított nyelvekkel |

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
| `maxImagePixels` | 16 000 000 | a nagyobb képet OCR előtt visszautasítja |
| `processTimeout` | 60 s | külső eszközönként |
| `processMemoryMb` | 512 | eszközönkénti címtér-plafon Linuxon; `0` kikapcsolja |
| `pdftotextBinary` | `null` | útvonal vagy parancsnév; `null` esetén a `PATH`-ban keres |
| `tesseractBinary` | `null` | útvonal vagy parancsnév; `null` esetén a `PATH`-ban keres |
| `ocrLanguages` | `eng` | Tesseract-nyelvek `+`-szal; mindnek telepítve kell lennie |
| `fallbackEncoding` | `Windows-1252` | a nem érvényes UTF-8 szövegfájlokhoz; magyar exportokhoz `Windows-1250` |

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
végzi. Az extractor által dobott kivételből `Failed/internal_error` lesz.

## Biztonság

A csomagot ismeretlen forrásból származó feltöltésekhez tervezték:

- **Zip-bomba.** Minden zip-bejegyzést számláló streamen át olvas. A kicsomagolás a
  `maxArchiveEntryBytes` / `maxArchiveTotalBytes` értéknél akkor is megáll, ha a fájl hazudik a
  méretéről. A `..`-ot tartalmazó vagy abszolút bejegyzésnevek soha nem kerülnek beolvasásra.
- **XML.** A hálózati hozzáférés, a DTD-betöltés és az entitásfeloldás ki van kapcsolva, a DOCTYPE-ot
  tartalmazó dokumentumot pedig visszautasítja, mert egyik támogatott formátum sem használ ilyet.
- **Külső eszközök.** A parancsok argumentumtömbként, shell nélkül indulnak, timeouttal, korlátos
  kimenettel, memóriaplafonnal és saját, üres munkakönyvtárral.
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
