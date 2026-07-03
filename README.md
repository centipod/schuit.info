# Schuit lokaal ontwikkelstation

Dit project zet WordPress en webtrees lokaal naast elkaar op met aparte databases en een gedeelde visuele laag.

## Starten

1. Kopieer `.env.example` naar `.env`.
2. Start de stack met `./scripts/bootstrap.sh`.
3. Importeer eerst beide databases met `./scripts/import-all.sh`.
4. Open `http://localhost:8081`.

## Importeren van de archieven

De SQL-archieven staan in `archive/`.

* Alles in één keer: `./scripts/import-all.sh`
* Alleen WordPress: `./scripts/import-archive.sh wordpress archive/schuit.sql.zip`
* Alleen webtrees: `./scripts/import-archive.sh webtrees archive/schuit_1.sql.zip`

## Lokaal resultaat

* WordPress draait op `/`.
* webtrees draait op `/tree/`.
* De databases blijven gescheiden.
* De portal gebruikt Nederlands als basis voor de eerste content.
* Een eigen webtrees-theme module in `modules_v4/` geeft de genealogie-browser een modernere look and feel.

## AWS IaC

The AWS production stack now lives under [infra/terraform](infra/terraform).
Bootstrap the remote state first with [infra/terraform/bootstrap](infra/terraform/bootstrap), then apply [infra/terraform/environments/prod](infra/terraform/environments/prod).

## Belangrijke opmerking

De webtrees-container gebruikt nu de huidige release-lijn (`2.2.6`). Als de oude dump een schema-upgrade nodig heeft, laat webtrees die na de eerste start uitvoeren.

De databasecontainers gebruiken een vastgepinde MySQL-versie (`mysql:8.4.5`) zodat de lokale opzet voorspelbaar blijft.
