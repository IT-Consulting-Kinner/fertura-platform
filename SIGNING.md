# Signatur- und Lizenzverfahren (Betreiber-/Marketplace-Seite)

Dieses Dokument beschreibt das **kryptografische** Verfahren für Paket-
Signaturen, Lizenzen und die Vertrauensankerverteilung. Es ergänzt das
Plattform-Dokument (Kap. 24.9, 28.7) und ist von der **rechtlichen**
Lizenzierung (`LICENSING.md`, AGPL/Open-Core) zu unterscheiden.

> Alle Signaturen sind **Ed25519** (Entscheidung E22). Schlüssel werden als
> base64 gehandhabt.

## 1. Schlüsselhierarchie (Root → Publisher)

```
Root-Schlüssel  (oberster Vertrauensanker, offline/HSM)
   │  signiert
   ├─ Publisher-Zertifikat A  →  Publisher-Schlüssel A (operativ)
   │                                  └─ signiert Pakete + Lizenzen des Publishers A
   ├─ Publisher-Zertifikat B  →  Publisher-Schlüssel B (operativ)
   └─ signiert Marketplace-Dokumente: anchors.json, crl.json, metadata.json
```

- **Root-Schlüssel:** Wurzel des Vertrauens. Wird **außerhalb des Bandes**
  (vertrauenswürdig, z. B. mit der Core-Auslieferung) als Anker installiert und
  **niemals online** gehalten. Empfehlung: HSM oder Offline-Medium, Zugriff
  mehraugengesichert. Der Root signiert ausschließlich (a) Publisher-Zertifikate
  und (b) Marketplace-Dokumente.
- **Publisher-Schlüssel:** operative Schlüssel je Herausgeber. Signieren Pakete
  und Lizenzen. Ihr **Zertifikat** (Bindung von `key_id`, `public_key`,
  `publisher`) wird vom Root signiert; der Core prüft diese Kette.

## 2. Schlüssel erzeugen

```bash
# Root-Schlüssel (einmalig, offline aufbewahren)
bin/cake mp_tool keygen        # -> public=… / secret=…  (als root-Anker + Root-Secret)

# Publisher-Schlüssel (je Herausgeber)
bin/cake mp_tool keygen        # -> public=… / secret=…  (Publisher-Public/-Secret)
```

## 3. Publisher-Zertifikat ausstellen (Root signiert Publisher)

```bash
bin/cake mp_tool sign-key \
  --secret "<ROOT_SECRET>" --key-id "<ROOT_KEY_ID>" \
  --pub-key "<PUBLISHER_PUBLIC>" --pub-id "<PUBLISHER_KEY_ID>" \
  --publisher "Beispiel GmbH"  > publisher-cert.json
```

`publisher-cert.json` enthält `key_signature` = Root-Signatur über die
kanonische Aussage `{key_id, public_key, publisher}`.

## 4. Vertrauensanker auf einer Instanz installieren

```bash
# Root direkt (out-of-band vertrauenswürdig)
bin/cake trust add-anchor "<ROOT_KEY_ID>" "<ROOT_PUBLIC>" --type root

# Publisher nur über Zertifikat – die Kette wird gegen den aktiven Root geprüft
bin/cake trust add-anchor --cert publisher-cert.json
```

Im Regelbetrieb werden Publisher-Anker über `marketplace.sync` verteilt
(`anchors.json`, vom Root signiert); der Core prüft dort **zusätzlich** jede
Publisher-Signatur gegen den Root (Defense-in-Depth).

## 5. Pakete signieren (Publisher)

```bash
bin/cake mp_tool sign "<paketverzeichnis>" --secret "<PUBLISHER_SECRET>" --key-id "<PUBLISHER_KEY_ID>"
# schreibt signature.json (Signatur über den Paket-Digest aller Dateien)
```

Bei `module install` prüft der Core: Anker aktiv & nicht widerrufen, **Kette zum
Root gültig**, Publisher-Bindung (Manifest), Signatur über den Paket-Digest.

## 6. Lizenzen ausstellen (Publisher)

```bash
bin/cake mp_tool license "<module_ref>" \
  --secret "<PUBLISHER_SECRET>" --key-id "<PUBLISHER_KEY_ID>" \
  --valid-to "2027-01-01T00:00:00+00:00" [--grace 14] [--online]  > license.json
```

Installation auf der Instanz: Upload über die Admin-GUI (Marketplace → Lizenzen)
oder `LicenseService::install()`. Offline-Prüfung gegen den Vertrauensanker.

## 7. Marketplace-Dokumente signieren (Root)

```bash
bin/cake mp_tool sign-doc anchors.json --secret "<ROOT_SECRET>" --key-id "<ROOT_KEY_ID>"
bin/cake mp_tool sign-doc crl.json     --secret "<ROOT_SECRET>" --key-id "<ROOT_KEY_ID>"
```

Gibt die Hülle `{payload, key_id, signature}` aus, die der `MarketplaceClient`
beim Sync verifiziert.

## 8. Rotation & Widerruf

- **Publisher-Rotation:** Neues Publisher-Schlüsselpaar erzeugen, neues
  Zertifikat vom Root signieren, in `anchors.json` aufnehmen. Alten Schlüssel
  über die CRL widerrufen (`crl.json` / `trust revoke`). Bereits installierte
  Module bleiben lauffähig; **neue** Pakete/Updates mit dem alten Schlüssel
  werden abgewiesen.
- **Root-Widerruf:** Ein Root-Widerruf entzieht **allen** darunter signierten
  Publisher-Schlüsseln nachträglich das Vertrauen (der Core prüft die Kette bei
  jeder Paketverifikation neu). Daher Root-Wechsel nur geplant und mit
  Verteilung eines neuen Root-Ankers.
- **Settings-/Verschlüsselungsschlüssel:** siehe `bin/cake secret rotate`
  (Re-Encryption verschlüsselter Settings).

## 9. Sicherheitsleitlinien

- Root-Secret niemals auf produktiven/online erreichbaren Systemen ablegen.
- Publisher-Secrets in einem Secret-Store/HSM des Herausgebers halten.
- Signaturoperationen (`sign-key`, `sign-doc`) bevorzugt auf einer abgesicherten
  Betreiber-Maschine ausführen, nicht auf der Instanz selbst.
- Das `mp_tool` ist das Betreiber-/Marketplace-Werkzeug; auf Instanzen wird nur
  **verifiziert** (PackageVerifier/LicenseService/TrustChain), nie signiert.
