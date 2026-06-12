# Instalacija na cPanel (Unlimited hosting)

Aplikacija radi na bilo kom PHP 7.4+ hostingu sa MySQL bazom.
Adresa: **https://ponude.aggroup.rs**

## Korak 1 — Subdomen

1. U cPanel-u otvori **Domains** (ili **Subdomains**)
2. Napravi subdomen `ponude.aggroup.rs`
3. Zapamti folder koji mu je dodeljen (npr. `public_html/ponude`)

## Korak 2 — Baza podataka

1. U cPanel-u otvori **MySQL® Databases**
2. Napravi novu bazu, npr. `ponude` (dobiće prefiks, npr. `aggroup_ponude`)
3. Napravi novog MySQL korisnika sa jakom lozinkom
4. Dodeli korisnika bazi sa **ALL PRIVILEGES**
5. Otvori **phpMyAdmin** → izaberi bazu → kartica **Import** → izaberi fajl `install.sql` → **Go**

## Korak 3 — Upload fajlova

1. U cPanel-u otvori **File Manager**
2. Uđi u folder subdomena (npr. `public_html/ponude`)
3. Upload-uj **sav sadržaj `app/` foldera** (api.php, index.php, login.php, p.php,
   .htaccess, lib/, assets/, config.sample.php)
   - Najlakše: napravi ZIP od sadržaja `app/` foldera, upload-uj pa **Extract**
4. **Preimenuj** `config.sample.php` u `config.php`
5. Otvori `config.php` (desni klik → Edit) i unesi:
   - ime baze (iz koraka 2)
   - MySQL korisnika i lozinku
   - `APP_URL` ostavi `https://ponude.aggroup.rs`

## Korak 4 — SSL (https)

U cPanel-u otvori **SSL/TLS Status** i uključi **AutoSSL** za `ponude.aggroup.rs`
(obično je automatski). Bez ovoga javni linkovi neće raditi preko https.

## Korak 5 — Prvo pokretanje

1. Otvori `https://ponude.aggroup.rs` u browseru
2. Prikazaće se forma **"Prvo pokretanje — napravi admin nalog"**
3. Unesi korisničko ime i lozinku (zapamti ih!)
4. Uđi u **⚙ Podešavanja** i popuni podatke firme:
   tekući račun, PIB, matični broj, PDV status, kurs evra

## Šta aplikacija radi

| Funkcija | Opis |
|---|---|
| **Nova ponuda** | Kalkulator pergola i stakla (isti kao stara aplikacija) |
| **Dokumenti** | Sve ponude, predračuni, avansni računi i fakture na jednom mestu |
| **Link za klijenta** | Dugme "🔗 Link" — pošalji preko Vibera/WhatsApp-a, klijent otvara lepu stranicu |
| **Konverzija** | Iz ponude jednim klikom: Predračun / Avansni račun / Faktura (EUR ili RSD) |
| **Ugovori** | Prihvaćene ponude i izdati računi (i pravna lica) — uplate i potraživanja, pojedinačno i ukupno |
| **PDF** | Sa pravim srpskim slovima (š, đ, č, ć, ž) |

## Napomena o fakturama (SEF)

Ako je firma u sistemu PDV-a i izdaje fakture pravnim licima, te fakture po zakonu
moraju da se registruju i u državnom sistemu eFaktura (SEF). PDF iz ove aplikacije
služi za klijenta i internu evidenciju.
