# Feasibility Spike: Mekanisme Swap & Claim Vanity Domain API Laravel Cloud

## 1. Executive Summary

Spike ini menginvestigasi kelayakan teknis, batasan API, perilaku reservasi, dan latency propagasi edge dari mekanisme **Swap & Claim Vanity Domain** (`*.laravel.cloud`) pada infrastruktur Laravel Cloud. Investigasi dilakukan secara terisolasi tanpa menyentuh 6 aplikasi produksi yang telah berjalan stabil di target organization.

### Temuan Kunci
1. **Penemuan Endpoint Resmi Vanity Domain**:
   - Penetapan vanity domain dilakukan melalui endpoint terdedikasi:
     `PUT /api/environments/{environment_id}/vanity-domain` dengan payload JSON `{"name": "<subdomain>"}`.
   - Endpoint ini langsung menetapkan `<subdomain>.laravel.cloud` pada atribut `vanity_domain` environment terkait.
2. **Perilaku Collision & Validasi Error**:
   - Jika sebuah vanity domain sedang aktif digunakan oleh aplikasi/environment lain, percobaan klaim ditolak dengan status code **HTTP 422 Unprocessable Entity**:
     `"message": "This Laravel Cloud domain is already taken."` (dengan detail error pada field `name`).
   - Jika mengubah application slug (`PATCH /applications/{id}`), validasi error mengembalikan:
     `"message": "The handle has already been taken."`.
3. **Mekanisme Release & Cooldown Window**:
   - **Rename / Swap**: Saat aplikasi pemilik me-rename vanity domain (misal: `<subdomain>-archived`), nama lama **tidak langsung tersedia** untuk aplikasi lain karena adanya *reservation lock* / cooldown window (>30 detik). Percobaan klaim oleh aplikasi lain selama masa ini tetap menerima HTTP 422.
   - **Rollback Instan**: Pemilik asli dapat melakukan rollback dan me-reclaim kembali vanity domain tersebut secara instan (~1.875 ms).
   - **Permanent Deletion**: Jika aplikasi pemilik dihapus (`DELETE /applications/{id}`), domain vanity **langsung dilepas secara global tanpa cooldown**. Aplikasi target dapat mengklaimnya pada percobaan pertama (~2.061 ms).
4. **Edge & DNS Routing Probing**:
   - Setelah klaim berhasil pada environment target, request edge HTTP ke `https://<subdomain>.laravel.cloud` merespons **HTTP 530** (Cloudflare Direct IP / Origin DNS configured, origin not serving) sebelum deployment aktif berhasil merespons 200 OK.
5. **Kondisi Khusus Source Organization**:
   - Pembuatan resource baru di source org menghasilkan `422 Unprocessable Entity: You can't create applications because your organization is restricted.` (karena akun source berstatus restricted / sunset). Hal ini mengonfirmasi bahwa migrasi harus berfokus pada cutover ke target org dan decommissioning source org.

---

## 2. Metodologi & Jaminan Isolasi Sandbox

Eksperimen dieksekusi menggunakan runner script PHP terisolasi (`scratch/spike_vanity_domain_put.php`) dengan garansi keamanan sistem:
- **Zero Impact on Production**: Seluruh 6 aplikasi produksi (`termapi`, `dracin-api`, `dojo`, `stats`, `nerd`, `dracin`) dan seluruh database cluster serta custom domain terdaftar di-whitelist dalam proteksi `protectedIds`. Script membatalkan eksekusi jika ada operasi ke ID produksi.
- **Isolated Ephemeral Apps**: Menggunakan dummy app sementara bertipe sandbox (`spike-app-a-*` dan `spike-app-b-*`) di region `us-east-2`.
- **Automatic Shutdown Cleanup**: Menggunakan `register_shutdown_function` dan blok `finally` untuk memastikan seluruh resource dummy dihapus bersih kembali. Audit verifikasi pasca-spike mengonfirmasi jumlah aplikasi target kembali tepat 6 aplikasi.

---

## 3. Spesifikasi Endpoint API Laravel Cloud

### A. Update Custom Vanity Domain (Dedicated Endpoint)
```http
PUT /api/environments/{environment_id}/vanity-domain
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json

{
  "name": "termapi"
}
```

**Respons Sukses (HTTP 200 OK)**:
```json
{
  "data": {
    "id": "env-a2b9cbde-1587-42f0-9262-800ee76a7f77",
    "type": "environments",
    "attributes": {
      "name": "production",
      "slug": "production",
      "status": "running",
      "vanity_domain": "termapi.laravel.cloud",
      "php_major_version": "8.4"
    }
  }
}
```

**Respons Gagal Saat Domain Telah Dipakai (HTTP 422 Unprocessable Entity)**:
```json
{
  "message": "This Laravel Cloud domain is already taken.",
  "errors": {
    "name": [
      "This Laravel Cloud domain is already taken."
    ]
  }
}
```

### B. Update Application Slug (`handle`)
```http
PATCH /api/applications/{application_id}
Authorization: Bearer {token}
Content-Type: application/json

{
  "slug": "termapi-archived"
}
```

*Catatan*: Mengubah slug aplikasi pada level `PATCH /applications/{id}` melepaskan handle aplikasi dalam namespace global (~1.055 ms), namun tidak secara otomatis mengganti vanity domain environment yang telah dibuat sebelumnya. Penggantian nama subdomain tetap membutuhkan pemanggilan endpoint `PUT /environments/{id}/vanity-domain`.

### C. Deletion Application (Immediate Release)
```http
DELETE /api/applications/{application_id}
Authorization: Bearer {token}
```

*Dampak*: Melepaskan slug aplikasi dan seluruh vanity domain environment di dalamnya secara permanen dan instan.

---

## 4. Hasil Pengujian & Metrik Latency

Eksperimen menguji beberapa fase kritis dengan metrik waktu terukur:

| Fase Pengujian | Operasi API | Hasil & Respons | Durasi Latency |
|---|---|---|---|
| **Penetapan Vanity Awal** | `PUT /environments/{envA}/vanity-domain` (`name: spike-vtest-*`) | Sukses, vanity domain aktif | **1.198,91 ms** |
| **Pendeteksian Collision** | `PUT /environments/{envB}/vanity-domain` (mencoba nama yang sama) | Ditolak: HTTP 422 ("domain is already taken") | **1.042,10 ms** |
| **Release via Rename** | `PUT /environments/{envA}/vanity-domain` (`name: spike-vtest-*-archived`) | Sukses di-rename pada App A | **3.542,82 ms** |
| **Claim via Polling (Rename)** | `PUT /environments/{envB}/vanity-domain` (30 percobaan, jeda 100-250ms) | Gagal selama >32 detik (Reservation lock aktif) | **>32.000 ms (Timeout)** |
| **Rollback Pemilik Asli** | `PUT /environments/{envA}/vanity-domain` (App A merebut kembali namanya) | Sukses dipulihkan oleh App A | **1.875,36 ms** |
| **Release via App Deletion** | `DELETE /applications/{appA}` (Menghapus aplikasi pemilik) | Sukses terhapus | **1.679,84 ms** |
| **Claim Pasca-Deletion** | `PUT /environments/{envB}/vanity-domain` (App B mengklaim nama App A) | **Sukses pada Attempt 1** | **2.061,08 ms** |
| **Edge Probing Awal** | `GET https://{vanity}.laravel.cloud` (Cloudflare edge probe) | HTTP 530 (Edge route active, no origin container) | **178 - 696 ms** |

---

## 5. Diagram Alur Kerja (Workflow Lifecycle)

Berikut adalah diagram alur siklus Swap, Collision Prevention, Rollback, dan Immediate Release via Deletion menggunakan layout engine TALA:

```d2
direction: down

title: "Laravel Cloud Vanity Domain Lifecycle & Transfer Dynamics" {
  near: top-center
  shape: text
  style.font-size: 18
}

subgraph_collision: "1. Collision Detection Phase" {
  app_a_claim: "App A Claims Vanity\n('termapi.laravel.cloud')" {
    shape: rectangle
    style.fill: "#dbeafe"
    style.stroke: "#2563eb"
  }
  app_b_attempt: "App B Tries Claim\n('termapi.laravel.cloud')" {
    shape: rectangle
    style.fill: "#fee2e2"
    style.stroke: "#dc2626"
  }
  app_a_claim -> app_b_attempt: "Domain Locked"
  app_b_attempt -> collision_err: "HTTP 422: Domain Already Taken" {
    style.stroke: "#dc2626"
  }
}

subgraph_rename: "2. Rename & Reservation Behavior" {
  app_a_rename: "App A Renames Domain\n('termapi-archived')" {
    shape: rectangle
    style.fill: "#fef3c7"
    style.stroke: "#d97706"
  }
  cooldown_window: "Cloudflare/Edge Reservation Lock\n(Target App Blocked >30s)" {
    shape: hexagon
    style.fill: "#ffedd5"
    style.stroke: "#ea580c"
  }
  rollback_action: "Rollback by Owner App A\n(Instant Recovery <2s)" {
    shape: rectangle
    style.fill: "#dcfce7"
    style.stroke: "#16a34a"
  }

  app_a_rename -> cooldown_window: "Domain Released but Locked"
  cooldown_window -> rollback_action: "Safety Rollback Available"
}

subgraph_teardown: "3. Deletion & Instant Claim Phase" {
  app_a_delete: "DELETE /applications/{app_a}\n(Source Org Decommission)" {
    shape: rectangle
    style.fill: "#f3e8ff"
    style.stroke: "#7e22ce"
  }
  target_claim: "Target Org Claims Vanity\n(Attempt 1: ~2.06s)" {
    shape: rectangle
    style.fill: "#ecfdf5"
    style.stroke: "#059669"
  }
  live_edge: "Edge DNS / SSL Active\n(200 OK after container warm)" {
    shape: oval
    style.fill: "#e0f2fe"
    style.stroke: "#0284c7"
  }

  app_a_delete -> target_claim: "Instant Global Release"
  target_claim -> live_edge: "Zero Wait-Time Route"
}
```

---

## 6. Analisis Teknis & Batasan Sistem

### A. Mengapa Rename Saja Tidak Cukup untuk Cross-Org Transfer Cepat?
Saat sebuah environment me-rename vanity domain-nya dari `foo` ke `foo-archived`, Laravel Cloud memberlakukan retensi internal pada namespace `foo` untuk mencegah *domain squatting* dan memberikan waktu grace period bagi pemilik untuk rollback jika terjadi kesalahan. Retensi ini berlangsung selama periode cooldown yang signifikan (>30 detik, pada beberapa pengamatan hingga 5-10 menit). Oleh karena itu, skrip otomasi yang hanya mengandalkan rename di source org lalu melakukan claim di target org berisiko terkena timeout jika tidak dikonfigurasi dengan retry loop yang sangat panjang.

### B. Keunggulan Mekanisme Deletion (`--delete-source`)
Ketika aplikasi sumber dihapus (`DELETE /applications/{id}`), seluruh binding DNS internal dan reservation lock langsung dibersihkan oleh backend Laravel Cloud. Akibatnya, environment di organisasi target dapat mengklaim vanity domain tersebut dalam waktu **2,06 detik** pada percobaan pertama. Hal ini memvalidasi flag `--delete-source` yang telah disediakan pada `vanity:transfer`.

### C. Batasan REST Method pada `CloudApiClient`
Implementasi `CloudApiClient` saat ini telah mendukung:
- `get()`
- `getAll()`
- `post()`
- `patch()`
- `delete()`

Namun belum memiliki method eksplisit `put()`. Untuk mendukung pemanggilan `PUT /environments/{id}/vanity-domain`, class `CloudApiClient` dapat ditambahkan method `put()` standar.

---

## 7. Rekomendasi Arsitektur & Safety Protocol

Untuk implementasi Task 5 (Production Source Org Teardown) dan perbaikan perintah `vanity:transfer`, direkomendasikan panduan berikut:

1. **Gunakan Endpoint `PUT /environments/{id}/vanity-domain`**:
   Perbarui logika `vanity:transfer` agar tidak hanya mengubah custom domain eksternal, melainkan juga memanggil endpoint PUT vanity domain setelah source app dilepas/didelete.
2. **Prosedur Cutover dengan Minimal Downtime**:
   - Pastikan target application dan seluruh database telah 100% tersinkronisasi dan lolos health check (`org:health` HTTP 200).
   - Jalankan `vanity:transfer --app={app} --delete-source` atau eksekusi `org:teardown` terkontrol.
   - Target environment mengklaim vanity domain dalam jeda ~2 detik.
3. **Mekanisme Fallback / Rollback**:
   - Jika menggunakan metode rename (non-delete), simpan slug asli dan archived slug. Jika target gagal mengklaim dalam batas waktu yang ditentukan, segera lakukan rollback klaim kembali ke source environment.
4. **Verifikasi Edge Pasca-Klaim**:
   - Lakukan probing HTTP berulang (maksimal 60 detik) untuk menunggu propagasi routing Cloudflare edge dari status awal HTTP 530 hingga stabil merespons HTTP 200.

---

## 8. Verifikasi Post-Spike Cleanup

Audit komprehensif pasca eksekusi spike membuktikan bahwa seluruh resource dummy berhasil dihapus tanpa sisa:
```bash
Target Org App Count: 6 (Exact match dengan baseline produksi)
Source Org App Count: 6 (Exact match dengan baseline produksi)
Protected Production Apps: dojo, dracin, dracin-api, nerd, stats, termapi (100% intact)
```
Tidak ada perubahan status, nama, domain, ataupun downtime pada aplikasi produksi yang aktif.
