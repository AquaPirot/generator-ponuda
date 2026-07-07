# Instalacija na cPanel (Unlimited hosting)

Aplikacija radi na bilo kom PHP 7.4+ hostingu sa MySQL bazom.
Adresa: **https://ponude.aggroup.rs**

## ⬆ NADOGRADNJA POSTOJEĆE INSTALACIJE NA v2 (poslovi)

Ako aplikacija već radi na serveru (stara verzija sa karticama
Početna/Dokumenti/Ugovori), uradi OVO umesto pune instalacije:

1. **phpMyAdmin** → izaberi bazu → kartica **SQL** → nalepi ceo sadržaj
   fajla `migrate-v2.sql` → **Go**. (Pokreće se samo JEDNOM!
   Postojeći klijenti, dokumenti i uplate se čuvaju — svaka ponuda
   postaje "posao".)
2. **File Manager** → u folder aplikacije prekopiraj (prepiši):
   `api.php`, `index.php`, `login.php`, `p.php`, `lib/db.php`,
   `assets/app.js`, `assets/style.css`
3. `config.php` NE diraš. `install.sql` NE pokrećeš (obrisao bi podatke).
4. Osveži stranicu (Ctrl+F5). Lozinka ostaje ista.

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
| **Poslovi** | Centralna lista: svaki posao = klijent + stavke + cena. Statusi: Ponuda → Ugovoreno → Realizacija → Završeno |
| **Presek** | Na vrhu uvek: Ugovoreno / Naplaćeno / **Potraživanja** (EUR) |
| **PDV automatski** | Fizičko lice → bez PDV · Pravno lice → PDV 20% · Pravno lice čl. 10 st. 2 t. 3 → bez PDV + zakonska napomena. Bira se na klijentu, jednom. |
| **Dokumenti iz posla** | Ponuda (automatski), Predračun, Avansni račun (vezan za uplatu), Konačni račun (automatski odbija avanse) — EUR ili RSD |
| **Link za klijenta** | Dugme "🔗 Link" — pošalji preko Vibera/WhatsApp-a |
| **Uplate** | Upis na poslu; predlog za izdavanje avansnog računa; dug po klijentu |
| **PDF** | Sa pravim srpskim slovima (š, đ, č, ć, ž) |

## Napomena o fakturama (SEF)

Ako je firma u sistemu PDV-a i izdaje fakture pravnim licima, te fakture po zakonu
moraju da se registruju i u državnom sistemu eFaktura (SEF). PDF iz ove aplikacije
služi za klijenta i internu evidenciju.
