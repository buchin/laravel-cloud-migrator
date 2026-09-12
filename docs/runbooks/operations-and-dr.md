# Operational Runbook & Disaster Recovery Standard Operating Procedures

Panduan operasional resmi dan prosedur penanganan bencana (*Disaster Recovery*) untuk ekosistem aplikasi Laravel Cloud pada organisasi target **Dojo** (`laravel-cloud-migrator`).

---

## 1. Topologi Sistem & Arsitektur Produksi

Infrastruktur pasca-migrasi beroperasi penuh pada Laravel Cloud dengan profil arsitektur terpadu:

- **6 Aplikasi Aktif**:
  1. `termapi` (Core API gateway, subdomain vanity: `termapi.laravel.cloud`)
  2. `dojo` (Web portal utama, vanity / custom domain)
  3. `stats` (Analytics engine)
  4. `nerd` (Service metadata & indexing)
  5. `dracin-api` (Backend streaming API, subdomain vanity: `dracin-api.laravel.cloud`)
  6. `dracin` (Frontend web streaming)
- **17 Environment Aktif**: Terdistribusi pada branch produksi (`production`, `main`) dan staging/ephemeral across all applications. Seluruh environment terkonfigurasi dengan PHP 8.2/8.4 dan auto-scaling.
- **Database Clusters**:
  - `dojo` cluster (`laravel_mysql`): Menampung schema `dojo.stats`, `dojo.nerd`, dan `dojo.dojo`.
  - `dracin_api` cluster (`laravel_mysql`): Menampung schema `dracin_api.production` dan `dracin_api.main`.
- **Cache Cluster**: 1 cluster Valkey terdistribusi (`laravel_valkey`) terhubung ke pool aplikasi `dojo`.
- **Object Storage**: S3-compatible multi-bucket storage (AWS S3 / Cloudflare R2 / MinIO) untuk asset media dan log.

### Diagram Topologi & Alur Trafik (D2 - Layout TALA)

```d2
direction: right

title: "Production Infrastructure & Operational Flow Topology" {
  near: top-center
  shape: text
  style.font-size: 18
}

internet: "End Users & Clients" {
  shape: person
  style.fill: "#f1f5f9"
}

edge_layer: "Cloudflare Edge & Custom Domains" {
  style.fill: "#e0f2fe"
  style.stroke: "#0284c7"

  cf_dns: "Edge DNS / SSL\n(*.laravel.cloud & Custom Domains)" {
    shape: hexagon
  }
}

apps_layer: "Laravel Cloud App Fleet (6 Apps / 17 Environments)" {
  style.fill: "#f8fafc"
  style.stroke: "#64748b"

  termapi: "termapi\n(Core Gateway)" {
    shape: rectangle
    style.fill: "#dcfce7"
  }
  dojo_app: "dojo & stats & nerd\n(Main Portal Fleet)" {
    shape: rectangle
    style.fill: "#fef3c7"
  }
  dracin_fleet: "dracin & dracin-api\n(Streaming Fleet)" {
    shape: rectangle
    style.fill: "#f3e8ff"
  }
}

data_layer: "Persistence & Storage Layer" {
  style.fill: "#f1f5f9"
  style.stroke: "#475569"

  db_dojo: "MySQL Cluster: dojo\n(Schemas: stats, nerd, dojo)" {
    shape: cylinder
    style.fill: "#dbeafe"
  }
  db_dracin: "MySQL Cluster: dracin_api\n(Schemas: production, main)" {
    shape: cylinder
    style.fill: "#dbeafe"
  }
  valkey_cache: "Valkey Cache Cluster\n(dojo session/cache)" {
    shape: cylinder
    style.fill: "#fee2e2"
  }
  s3_storage: "S3 / R2 Object Storage\n(Media Buckets)" {
    shape: cylinder
    style.fill: "#ffedd5"
  }
}

ops_suite: "Operational CLI Suite\n(./cloud-migrator)" {
  shape: page
  style.fill: "#fef9c3"
  style.stroke: "#ca8a04"

  tool_audit: "org:audit\n(Health, DB, Config)"
  tool_vanity: "vanity:transfer\n(Domain Cutover)"
  tool_backfill: "db:backfill\n(Massive Table Replicate)"
  tool_storage: "storage:verify\n(Zero-Download Parity)"
}

internet -> edge_layer.cf_dns: "HTTPS / TLS Requests"
edge_layer.cf_dns -> apps_layer.termapi: "Gateway Route"
edge_layer.cf_dns -> apps_layer.dojo_app: "Web Traffic"
edge_layer.cf_dns -> apps_layer.dracin_fleet: "API & Web Traffic"

apps_layer.dojo_app -> data_layer.db_dojo: "PDO Reads / Writes"
apps_layer.dojo_app -> data_layer.valkey_cache: "Cache Reads / Hits"
apps_layer.dracin_fleet -> data_layer.db_dracin: "PDO Reads / Writes"
apps_layer.dracin_fleet -> data_layer.s3_storage: "Media Read / Write"

ops_suite -> apps_layer: "Probes & Config Audit"
ops_suite -> data_layer: "Parity Verification & Backfill"
```

---

## 2. Prosedur Cutover Domain & Vanity Transfer

Perintah `./cloud-migrator vanity:transfer` digunakan untuk memindahkan nama vanity domain (`<name>.laravel.cloud`) dari satu environment ke environment lain tanpa risiko kehilangan kepemilikan domain.

### A. Sintaks & Parameter CLI

```bash
./cloud-migrator vanity:transfer \
  --source-app="termapi-legacy" \
  --source-env="production" \
  --target-app="termapi" \
  --target-env="production" \
  --vanity="termapi" \
  --max-retries=30 \
  --retry-delay=2000 \
  --yes
```

**Daftar Parameter**:
| Opsi | Tipe | Deskripsi |
|---|---|---|
| `--source-app` | string | Nama atau slug aplikasi sumber |
| `--source-env` | string | Nama atau slug environment sumber |
| `--target-app` | string | Nama atau slug aplikasi target |
| `--target-env` | string | Nama atau slug environment target |
| `--vanity` | string | Subdomain vanity (otomatis dideteksi dari sumber bila diabaikan) |
| `--delete-source` | flag | Menghapus environment sumber untuk pelepasan instan tanpa cooldown lock |
| `--max-retries` | int | Jumlah maksimum percobaan retry saat lock aktif (default: 30) |
| `--retry-delay` | int | Jeda dasar antar percobaan dalam milidetik (default: 2000 ms) |
| `--yes` | flag | Menjalankan operasi non-interaktif tanpa konfirmasi manual |

### B. Mekanisme Pelepasan: Safe Archive vs Instant Deletion

1. **Safe Archive Mode (Default, `--delete-source=false`)**:
   - Environment sumber di-rename menjadi nama arsip acak: `{vanity}-archived-{random}` via `PUT /environments/{id}/vanity-domain`.
   - Backend Laravel Cloud memberlakukan **reservation lock** / cooldown window (>30 detik).
   - Tool mengeksekusi polling klaim secara berkala dengan dynamic backoff (multiplier 1.5x, max 5s) hingga status 422 teratasi.
   - **Keuntungan**: Zero data loss pada sumber; dapat dilakukan rollback instan jika target mengalami anomali.
2. **Instant Deletion Mode (`--delete-source=true`)**:
   - Digunakan saat fase decommissioning final ketika sumber sudah dipastikan tidak diperlukan lagi.
   - Environment sumber dihapus via `DELETE /environments/{id}`.
   - Backend Laravel Cloud langsung menghapus seluruh reservation lock secara global dalam ~2,06 detik.
   - Target environment dapat mengklaim vanity domain seketika pada *Attempt 1*.

### C. Penanganan Cooldown Lock (HTTP 422) & Collision

- **Respon 422**: `"message": "This Laravel Cloud domain is already taken."`.
- Service `VanityTransferService` otomatis mendeteksi kode status HTTP 422 dan pesan reservasi.
- Mekanisme retry menerapkan jeda progresif:
  $$\text{Delay}_k = \min(\text{retry\_delay} \times 1.5^k, 5000\text{ ms})$$
- Jika percobaan melebihi batas `--max-retries`, siklus memicu **Automatic Safety Rollback**.

### D. Garansi Rollback Instan

Jika klaim target gagal karena timeout atau error edge:
- Tool secara otomatis memanggil `PUT /environments/{source_env}/vanity-domain` dengan subdomain asli.
- Hak pemilik asli memulihkan kepemilikan nama dalam <2 detik (~1.875 ms).
- Notifikasi darurat dan log historis disajikan di terminal.

### E. Verifikasi Pasca-Transfer & Penanganan HTTP 530

- Tepat setelah klaim sukses, Cloudflare edge merespons **HTTP 530** (*Origin DNS configured, origin container starting/warming*).
- **Prosedur**:
  1. Tunggu 15-30 detik untuk *container warm-up*.
  2. Jalankan health probe:
     ```bash
     curl -I -s -o /dev/null -w "%{http_code}\n" https://<vanity>.laravel.cloud
     ```
  3. Pastikan respons berubah dari HTTP 530 menjadi **HTTP 200 OK**.

---

## 3. Prosedur Background Data Backfill

Perintah `./cloud-migrator db:backfill` memfasilitasi pengisian data historis masif (misal: ribuan hingga jutaan baris pada tabel `episodes`, `links`, `nerd_urls`) tanpa mengunci tabel (*table lock*) dan tanpa membebani IOPS database produksi.

### A. Sintaks & Penggunaan CLI

```bash
./cloud-migrator db:backfill \
  --schema="dracin_api.production" \
  --table="episodes" \
  --batch-size=5000 \
  --sleep-ms=50 \
  --adaptive \
  --resume \
  --yes
```

### B. Strategi Chunking & Idempotent Inserts

1. **Dynamic PK-Range Chunking (Default)**:
   - Mendeteksi Primary Key integer (`id`) via koneksi PDO.
   - Menghitung rentang minimum dan maksimum:
     $$\text{Range} = [\min(\text{pk}), \max(\text{pk})]$$
   - Mengambil data per batch menggunakan klausa terindeks non-locking:
     ```sql
     SELECT * FROM `table` WHERE `id` >= :start_id AND `id` <= :end_id ORDER BY `id` ASC LIMIT :limit
     ```
   - Mencegah *filesort* dan *table scan* penuh.
2. **Limit-Offset Fallback**:
   - Digunakan otomatis untuk tabel tanpa integer primary key tunggal (misal UUID atau composite key).
3. **Idempotensi Penulisan**:
   - Menggunakan query `INSERT IGNORE INTO` (MySQL) atau `ON CONFLICT DO NOTHING` (PostgreSQL).
   - Menghindari konflik duplikasi primary key saat proses diulang atau dilanjutkan.

### C. Adaptive Batch Sizing & IOPS Throttling

- **Throttling**: Jeda istirahat terukur antara batch (`--sleep-ms=50`) memberikan jeda waktu bagi I/O thread database untuk melayani transaksi produksi reguler.
- **Adaptive Batching (`--adaptive`)**:
  - Latensi eksekusi tiap batch dihitung secara real-time.
  - Jika latensi batch > 1.5 detik (3x target): ukuran batch otomatis diturunkan 30%.
  - Jika latensi batch > 1.0 detik (2x target): ukuran batch diturunkan 15%.
  - Jika latensi batch < 0.2 detik: ukuran batch dinaikkan 20% (hingga batas 50.000 baris).

### D. Stateful Checkpointing & Resume Protocol

- State eksekusi dicatat secara berkala ke `.backfill-state.json`.
- Metadata yang disimpan meliputi:
  ```json
  {
    "dracin_api.production.episodes": {
      "strategy": "pk_range",
      "pk_column": "id",
      "last_processed_id": 145000,
      "processed_rows": 145000,
      "total_rows": 500000,
      "status": "in_progress"
    }
  }
  ```
- **Prosedur Interupsi & Resume**:
  - Jika proses terhenti oleh sinyal `SIGINT` (Ctrl+C) atau kegagalan jaringan: state tersimpan aman.
  - Jalankan kembali perintah yang sama dengan menambahkan opsi `--resume`.
  - Tool melanjutkan replikasi dari `last_processed_id + 1` tanpa memproses ulang data sebelumnya.

---

## 4. Prosedur Object Storage Parity Audit

Perintah `./cloud-migrator storage:verify` mengaudit konsistensi file antar storage bucket tanpa mengunduh fisik objek ke mesin lokal (*Zero-Download Guarantee*).

### A. Sintaks & Parameter CLI

```bash
./cloud-migrator storage:verify \
  --buckets="s3-prod-source:s3-prod-target" \
  --prefix="uploads/" \
  --manifest="migration-plan.json" \
  --json="storage-parity-report.json" \
  --only-mismatches \
  --yes
```

### B. Arsitektur Zero-Download & Pre-Indexed Cache

1. **Zero-Download Guarantee**:
   - Audit hanya memeriksa metadata: keberadaan objek, ukuran byte (`Content-Length`), dan hash `ETag`.
   - Menggunakan paginasi `ListObjectsV2` dan query `HeadObject` (Flysystem `checksum` dengan algoritma `etag`).
   - Tidak ada payload objek yang ditransfer melalui kabel, menghemat bandwidth gigabytes hingga terabytes.
2. **Pre-Indexed Target Cache**:
   - Seluruh objek bucket target pada prefix yang ditentukan diindeks ke dalam memori (`$targetMap`).
   - Pencarian status target dari objek sumber berjalan dalam kompleksitas waktu $O(1)$, memangkas waktu audit dari jam menjadi detik.

### C. Normalisasi Multipart ETag

- Objek berukuran besar (>5MB atau >100MB) diunggah menggunakan teknik S3 multipart upload, menghasilkan format ETag khusus: `<md5_of_part_checksums>-<part_count>` (contoh: `c3b88e1a6b0c...-12`).
- Service `StorageVerifyService` menerapkan regex parser `^[a-f0-9]+-\d+$` dan normalisasi kutipan (`"..."`), menjamin kecocokan hash multipart antara AWS S3 dan Cloudflare R2.

### D. Matriks Klasifikasi Status & Remediasi

| Status | Deskripsi | Tindakan Remediasi |
|---|---|---|
| `✓ matched` | Ukuran dan checksum identik 100% | Tidak diperlukan tindakan. |
| `✗ missing_in_target` | Objek ada di sumber namun tidak ada di target | Jalankan sinkronisasi spesifik: `./cloud-migrator storage:sync --prefix="path/to/missing"` |
| `≠ size_mismatch` | Objek ada di kedua sisi tetapi ukuran byte berbeda | Unggah ulang objek dari sumber dan override di target. |
| `# checksum_mismatch` | Ukuran sama namun ETag berbeda (potensi korupsi) | Jalankan verifikasi integritas file lokal dan sinkronisasi ulang. |

---

## 5. Prosedur Deteksi Drift Lingkungan & CI/CD Runner

Deteksi berkala dilakukan untuk menjamin konfigurasi lingkungan tidak bergeser (*configuration drift*), database tetap sinkron, dan seluruh 17 endpoint merespons HTTP 200.

### A. Eksekusi Lokal & CLI Runner

```bash
./cloud-migrator org:audit \
  --manifest="migration-plan.json" \
  --json="audit-report.json" \
  --timeout=15 \
  --strict \
  --yes
```

**Tiga Pilar Audit `org:audit`**:
1. **HTTP Health Checks**: Menguji 17 environment via pool asynchronous concurrent request. Memastikan respons HTTP 200 OK dan latensi <500ms.
2. **Database Parity & Schema Drift**: Menginspeksi skema database target vs manifest dan sumber. Menghitung jumlah baris data tabel produksi dan mendeteksi tabel hilang.
3. **Configuration & Cross-App URLs**: Memvalidasi environment variables yang mereferensikan aplikasi lain (contoh: `API_BASE_URL={{apps.dracin-api.environments.production.url}}`) dan mendeteksi unmapped placeholder.

### B. Otomasi GitHub Actions Workflow (`.github/workflows/scheduled-audit.yml`)

Workflow scheduled audit dijalankan secara otomatis:
- **Jadwal Cron**: Setiap 6 jam (`0 */6 * * *`).
- **Manual Dispatch**: Dapat dipicu kapan saja dari tab Actions GitHub (`workflow_dispatch`).
- **Artifact**: Hasil audit tersimpan dalam format JSON (`audit-report.json`) dengan retensi 30 hari.

### C. Prosedur Recovery Drift Terdeteksi

#### 1. Drift HTTP Health Check (Status: `unhealthy` / `unreachable`)
- **Penyebab**: Aplikasi mengalami 500 error, deployment container crash, atau domain DNS unresolvable.
- **Langkah Pemulihan**:
  1. Periksa log insiden runtime:
     ```bash
     fural-agent organization resource-incidents --limit 20
     ```
  2. Buka log container environment:
     ```bash
     fural-agent sandboxes logs
     ```
  3. Jika container dalam status restart-loop, perbaiki env variable yang salah konfigurasi lalu trigger redeploy via dashboard atau CLI:
     ```bash
     fural-agent repos ship <REPO_ID>
     ```

#### 2. Drift Database (Status: `drift` / Mismatch Baris)
- **Penyebab**: Transaksi masuk selama jeda migrasi atau replikasi belum lengkap pada tabel besar.
- **Langkah Pemulihan**:
  1. Identifikasi tabel spesifik dari visual report `org:audit`.
  2. Jalankan backfill terisolasi untuk tabel tersebut:
     ```bash
     ./cloud-migrator db:backfill --schema="<schema>" --table="<table>" --resume --yes
     ```
  3. Verifikasi ulang menggunakan `./cloud-migrator db:verify`.

#### 3. Drift Konfigurasi & Placeholder Unresolved (Status: `drift` / `unresolved_placeholder`)
- **Penyebab**: Variabel lingkungan diatur manual di dashboard dan tidak sengaja mengubah cross-app URL atau menyisakan format template mentah `{{apps...}}`.
- **Langkah Pemulihan**:
  1. Jalankan sinkronisasi konfigurasi deklaratif dari manifest:
     ```bash
     ./cloud-migrator migrate --only=config --yes
     ```
  2. Jalankan ulang `org:audit --skip-health --skip-db` untuk memastikan seluruh variabel telah bersih dan matched.

---

## 6. SOP Penanganan Insiden & Disaster Recovery (DR)

### A. Matriks Tingkat Keparahan Insiden (Severity Matrix)

| Level | Kriteria | Target Tanggap (SLA) | Eskalasi |
|---|---|---|---|
| **P1 - Critical** | Seluruh aplikasi utama down (5xx), kehilangan data database, atau kegagalan DNS global | < 15 Menit | Lead Engineer, Product Owner, DevOps On-Call |
| **P2 - Major** | Satu environment down, anomali sinkronisasi cross-app, atau lonjakan latensi > 2.000ms | < 1 Jam | DevOps On-Call, Backend Team |
| **P3 - Minor** | Peringatan drift konfigurasi non-kritis, perbedaan tabel transient (cache/sessions) | < 4 Jam | Development Team |

### B. Protokol Disaster Recovery Database

Jika database cluster mengalami kegagalan fatal, kehilangan data, atau korupsi skema:

1. **Aktivasi Pemeliharaan (Maintenance Mode)**:
   - Pasang halaman pemeliharaan sementara untuk mencegah transaksi kotor masuk ke database:
     ```bash
     # Pasang mode maintenance pada environment terdampak
     ```
2. **Evaluasi Snapshot & Point-in-Time Recovery (PITR)**:
   - Laravel Cloud database cluster menyediakan automatic hourly snapshot dan binary log retention.
   - Buka portal Laravel Cloud Database Management untuk memilih timestamp PITR sesaat sebelum insiden terjadi.
3. **Pemulihan Database Sekunder**:
   - Pulihkan snapshot ke database instance baru atau restore in-place.
   - Uji koneksi via `./cloud-migrator db:verify` untuk memastikan keutuhan skema dan tabel.
4. **Resinkronisasi Data Transaksional**:
   - Jika data historis perlu direhidrasi dari backup luar, jalankan pipeline `./cloud-migrator db:backfill`.
5. **Nonaktifkan Mode Maintenance & Live Audit**:
   - Jalankan `./cloud-migrator org:audit --strict` untuk memvalidasi pemulihan 100%.

### C. Protokol Disaster Recovery Object Storage

Jika bucket penyimpanan objek mengalami insiden penghapusan massal atau kehilangan data:

1. **Isolasi & Akses Bucket**:
   - Bekukan write permissions untuk mencegah penimpaan file lebih lanjut.
2. **Pemanfaatan S3 Versioning & Lifecycle**:
   - Jika bucket mengaktifkan S3 Versioning, hapus *Delete Markers* untuk mengembalikan file ke versi aktif.
3. **Resinkronisasi dari Backup Storage / Mirror Bucket**:
   - Jalankan tool verifikasi untuk memetakan file yang hilang:
     ```bash
     ./cloud-migrator storage:verify --buckets="backup-bucket:prod-bucket" --only-mismatches --json="missing.json"
     ```
   - Jalankan sinkronisasi pemulihan:
     ```bash
     ./cloud-migrator storage:sync --source-bucket="backup-bucket" --target-bucket="prod-bucket" --yes
     ```
4. **Verifikasi Integritas**:
   - Jalankan ulang `./cloud-migrator storage:verify` hingga hasil paritas mencapai **100% Matched**.

### D. SOP Failover Regional / Outage Cloud Provider

Jika terjadi gangguan infrastruktur berskala luas pada region Laravel Cloud (misal: AWS outage pada region tertentu):

```d2
direction: down

title: "Disaster Recovery Regional Failover Workflow" {
  near: top-center
  shape: text
  style.font-size: 16
}

detect: "1. Incident Detection\n(org:audit alerts P1 outage)" {
  shape: rectangle
  style.fill: "#fee2e2"
  style.stroke: "#dc2626"
}

declare: "2. Declare P1 Emergency\n(PO & Lead Engineer notified)" {
  shape: rectangle
  style.fill: "#ffedd5"
  style.stroke: "#ea580c"
}

standby: "3. Provision/Activate Standby Region\n(Target Org secondary region)" {
  shape: rectangle
  style.fill: "#fef3c7"
  style.stroke: "#d97706"
}

restore_db: "4. Restore Database from Latest Snapshot\n(PITR Recovery)" {
  shape: rectangle
  style.fill: "#dbeafe"
  style.stroke: "#2563eb"
}

cutover_dns: "5. DNS & Vanity Cutover\n(./cloud-migrator vanity:transfer)" {
  shape: rectangle
  style.fill: "#dcfce7"
  style.stroke: "#16a34a"
}

verify: "6. E2E Health & Parity Verification\n(./cloud-migrator org:audit --strict)" {
  shape: rectangle
  style.fill: "#ecfdf5"
  style.stroke: "#059669"
}

detect -> declare: "Alert Threshold Exceeded"
declare -> standby: "Initiate Failover Plan"
standby -> restore_db: "Infrastructure Ready"
restore_db -> cutover_dns: "Data Consistent"
cutover_dns -> verify: "Traffic Switched"
```

1. **Deklarasi Keadaan Darurat**: Product Owner dan Lead Architect menetapkan status bencana regional.
2. **Penyediaan Environment di Region Alternatif**: Deploy aplikasi pada region cadangan menggunakan deklarasi `migration-plan.json`.
3. **Pemulihan Snapshot Database**: Restore snapshot database terbaru ke cluster region cadangan.
4. **Alih Trafik DNS & Vanity Transfer**:
   - Arahkan custom domain via Cloudflare DNS ke endpoint baru.
   - Pindahkan vanity domain menggunakan `./cloud-migrator vanity:transfer`.
5. **Verifikasi E2E**:
   - Jalankan `./cloud-migrator org:audit --strict` untuk memastikan sistem beroperasi normal 100%.

### E. Template Root-Cause Analysis (RCA) Post-Mortem

Setiap insiden P1 atau P2 wajib ditindaklanjuti dengan dokumen RCA dalam 48 jam pasca pemulihan:

```markdown
# Incident Post-Mortem RCA: [Judul Insiden]

## Ringkasan Eksekutif
- **Tanggal & Waktu Kejadian**: [YYYY-MM-DD HH:MM WIB]
- **Durasi Insiden**: [X Jam Y Menit]
- **Tingkat Keparahan**: P1 / P2
- **Dampak Bisnis**: [Deskripsi dampak pada user, data, transaksi]

## Kronologi Insiden (Timeline)
- **HH:MM** - Anomali pertama kali terdeteksi oleh [sistem/user/org:audit].
- **HH:MM** - Status insiden dideklarasikan dan tim on-call dimobilisasi.
- **HH:MM** - Akar masalah berhasil diidentifikasi.
- **HH:MM** - Tindakan mitigasi / failover dieksekusi.
- **HH:MM** - Sistem kembali normal dan diverifikasi via `./cloud-migrator org:audit`.

## Akar Masalah (Root Cause)
- [Penjelasan teknis mendalam mengenai penyebab terjadinya insiden]

## Analisis 5-Whys
1. Mengapa sistem down? ...
2. Mengapa hal itu terjadi? ...
3. Mengapa tidak terdeteksi sebelumnya? ...
4. Mengapa pencegahan gagal? ...
5. Mengapa arsitektur mengizinkan hal tersebut? ...

## Tindakan Pencegahan & Rencana Perbaikan (Action Items)
| Item Pekerjaan | PIC | Target Selesai | Status |
|---|---|---|---|
| Perbaikan batas timeout & alert | [Nama] | YYYY-MM-DD | Open |
| Penambahan unit/feature test di Pest | [Nama] | YYYY-MM-DD | Open |
```

---

## 7. Rangkuman Perintah Operasional Cepat (Cheat Sheet)

| Operasi | Perintah Eksekusi |
|---|---|
| **E2E Audit Penuh** | `./cloud-migrator org:audit --strict --yes` |
| **Audit Health Saja** | `./cloud-migrator org:audit --skip-db --skip-config --yes` |
| **Audit Paritas Database** | `./cloud-migrator db:verify --yes` |
| **Audit Paritas Storage** | `./cloud-migrator storage:verify --manifest=migration-plan.json --only-mismatches --yes` |
| **Cutover Vanity Domain** | `./cloud-migrator vanity:transfer --source-app=X --target-app=Y --yes` |
| **Backfill Data Historis** | `./cloud-migrator db:backfill --schema=S --table=T --resume --adaptive --yes` |
| **Ekspor Laporan Audit JSON**| `./cloud-migrator org:audit --json=audit-report.json --yes` |
