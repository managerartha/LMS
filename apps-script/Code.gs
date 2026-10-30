/**
 * LMS Artha Safety Indonesia — API (Google Apps Script).
 *
 * Tampilan LMS (folder web/) di-hosting di domain sendiri dan memanggil API ini lewat
 * HTTP POST ke URL /exec: body JSON {action, data}. Semua aksi masuk lewat api(action, data).
 * Data disimpan di satu Google Spreadsheet, file di Google Drive.
 */

/** Alamat tampilan LMS. Membuka URL /exec di browser akan diarahkan ke sini. */
const WEB_URL = 'https://lms.arthasafetyindonesia.com';

const TZ = 'Asia/Jakarta';
const DB_NAME = 'LMS Artha Safety - Database';
const ROOT_FOLDER_NAME = 'LMS Artha Safety';

/** Struktur sheet: nama sheet -> daftar kolom (baris pertama). */
const SHEETS = {
  Batch: ['id_batch', 'nama_pelatihan', 'kode_batch', 'lokasi_nama', 'tgl_mulai', 'tgl_selesai',
    'sesi1_mulai', 'sesi1_selesai', 'sesi2_mulai', 'sesi2_selesai', 'lat', 'lng', 'radius_meter',
    'lewati_weekend', 'pic_ids', 'folder_id', 'status', 'dibuat_pada'],
  Pengguna: ['id_user', 'id_batch', 'no_absen', 'nama', 'role', 'kode_login', 'pin_hash', 'instansi',
    'no_hp', 'device_id', 'token', 'setuju_privasi', 'ttd_id', 'aktif', 'dibuat_pada'],
  Hari: ['id_hari', 'id_batch', 'tanggal', 'hari_ke', 'lokasi_nama', 'lat', 'lng', 'radius_meter', 'diubah_oleh'],
  Absensi: ['id_absen', 'id_batch', 'id_hari', 'id_user', 'sesi', 'waktu_server', 'foto_id', 'ttd_id',
    'lat', 'lng', 'akurasi_meter', 'jarak_meter', 'status', 'keterangan', 'diubah_oleh', 'diubah_pada'],
  Rundown: ['id_rundown', 'id_batch', 'id_hari', 'urutan', 'jam_mulai', 'jam_selesai', 'kegiatan',
    'pemateri', 'keterangan', 'status'],
  Materi: ['id_materi', 'id_batch', 'id_hari', 'judul', 'kategori', 'file_id', 'tipe_file', 'link',
    'tampil_mulai', 'diupload_oleh', 'diupload_pada'],
  Dokumentasi: ['id_dok', 'id_batch', 'id_hari', 'id_rundown', 'file_id', 'keterangan', 'masuk_laporan',
    'diupload_oleh', 'diupload_pada', 'file_kecil_id'],
  Laporan: ['id_laporan', 'id_batch', 'jenis', 'id_hari', 'file_id', 'dibuat_oleh', 'dibuat_pada', 'nama_file'],
  Log: ['waktu', 'id_user', 'nama', 'aksi', 'detail'],
};

// ---------------------------------------------------------------- API (web app)

/** Permintaan dari tampilan LMS. Body dikirim sebagai text/plain berisi JSON {action, data}. */
function doPost(e) {
  let req = {};
  try {
    req = JSON.parse(e.postData.contents);
  } catch (err) {
    req = {};
  }
  return ContentService.createTextOutput(JSON.stringify(api(req.action, req.data)))
    .setMimeType(ContentService.MimeType.JSON);
}

/** Membuka URL API langsung di browser: arahkan ke alamat LMS. */
function doGet() {
  return HtmlService.createHtmlOutput(
    '<p style="font-family:sans-serif">LMS Artha Safety Indonesia ada di ' +
    '<a href="' + WEB_URL + '" target="_top">' + WEB_URL + '</a></p>' +
    '<script>window.top.location.href = ' + JSON.stringify(WEB_URL) + ';</script>')
    .setTitle('LMS Artha Safety Indonesia');
}

/** Isi file HTML di project (dipakai untuk logo laporan PDF: aset-logo). */
function include(name) {
  return HtmlService.createHtmlOutputFromFile(name).getContent();
}

/** Daftar aksi API. auth: '' = publik, 'user' = sudah login, 'pic' = PIC/Admin, 'admin' = Admin. */
function routes_() {
  return {
    // publik
    waktu: { auth: '', fn: () => ({ now: Date.now() }) },
    batchAktif: { auth: '', fn: batchAktif },
    loginPeserta: { auth: '', fn: loginPeserta },
    loginPic: { auth: '', fn: loginPic },
    // semua pengguna
    me: { auth: 'user', fn: me },
    beranda: { auth: 'user', fn: beranda },
    logout: { auth: 'user', fn: logout },
    setuju: { auth: 'user', fn: setuju },
    // peserta
    statusHariIni: { auth: 'user', fn: statusHariIni },
    absen: { auth: 'user', fn: absen },
    riwayat: { auth: 'user', fn: riwayat },
    kontenPeserta: { auth: 'user', fn: kontenPeserta },
    // PIC
    gantiPin: { auth: 'pic', fn: gantiPin },
    batchList: { auth: 'pic', fn: batchList },
    batchUbah: { auth: 'pic', fn: batchUbah },
    hariList: { auth: 'pic', fn: hariList },
    hariSetLokasi: { auth: 'pic', fn: hariSetLokasi },
    hariResetLokasi: { auth: 'pic', fn: hariResetLokasi },
    lokasiDariTeks: { auth: 'pic', fn: lokasiDariTeks },
    pesertaList: { auth: 'pic', fn: pesertaList },
    pesertaTambah: { auth: 'pic', fn: pesertaTambah },
    pesertaUbah: { auth: 'pic', fn: pesertaUbah },
    pesertaResetDevice: { auth: 'pic', fn: pesertaResetDevice },
    pesertaAktif: { auth: 'pic', fn: pesertaAktif },
    monitor: { auth: 'pic', fn: monitor },
    absenSetStatus: { auth: 'pic', fn: absenSetStatus },
    absenFoto: { auth: 'pic', fn: absenFoto },
    rundownGet: { auth: 'pic', fn: rundownGet },
    rundownSimpan: { auth: 'pic', fn: rundownSimpan },
    rundownSalin: { auth: 'pic', fn: rundownSalin },
    materiList: { auth: 'pic', fn: materiList },
    materiTambah: { auth: 'pic', fn: materiTambah },
    materiHapus: { auth: 'pic', fn: materiHapus },
    dokList: { auth: 'pic', fn: dokList },
    dokTambah: { auth: 'pic', fn: dokTambah },
    dokUbah: { auth: 'pic', fn: dokUbah },
    dokHapus: { auth: 'pic', fn: dokHapus },
    ttdSaya: { auth: 'pic', fn: ttdSaya },
    ttdSayaSimpan: { auth: 'pic', fn: ttdSayaSimpan },
    laporanList: { auth: 'pic', fn: laporanList },
    laporanBuat: { auth: 'pic', fn: laporanBuat },
    laporanUnduh: { auth: 'pic', fn: laporanUnduh },
    // Admin
    batchBuat: { auth: 'admin', fn: batchBuat },
    picList: { auth: 'admin', fn: picList },
    picTambah: { auth: 'admin', fn: picTambah },
    picResetPin: { auth: 'admin', fn: picResetPin },
  };
}

function api(action, data) {
  try {
    const route = routes_()[action];
    if (!route) throw new Error('Aksi tidak dikenal: ' + action);
    data = data || {};
    let user = null;
    if (route.auth) {
      user = userFromToken_(data.token);
      if (route.auth === 'pic' && !isPic_(user)) throw new Error('Menu ini khusus PIC/Admin.');
      if (route.auth === 'admin' && user.role !== 'admin') throw new Error('Menu ini khusus Admin.');
    }
    return { ok: true, data: route.fn(data, user) };
  } catch (err) {
    console.error(action, err);
    return { ok: false, error: err && err.message ? err.message : String(err) };
  }
}

// ---------------------------------------------------------------- Database (Spreadsheet)

let tableCache_ = {};

function ss_() {
  const id = PropertiesService.getScriptProperties().getProperty('DB_ID');
  if (!id) throw new Error('LMS belum di-setup. Jalankan fungsi setup() di editor Apps Script.');
  return SpreadsheetApp.openById(id);
}

/** Membaca seluruh sheet sebagai array objek {kolom: nilai teks, _row: nomor baris}. */
function table_(name) {
  if (tableCache_[name]) return tableCache_[name];
  const sh = ss_().getSheetByName(name);
  if (!sh) throw new Error('Sheet ' + name + ' tidak ditemukan. Jalankan setup() lagi.');
  const values = sh.getDataRange().getDisplayValues();
  const head = values[0];
  const rows = values.slice(1).map((r, i) => {
    const o = { _row: i + 2 };
    head.forEach((k, j) => { o[k] = r[j]; });
    return o;
  });
  tableCache_[name] = { sh: sh, head: head, rows: rows };
  return tableCache_[name];
}

/** Membuang cache agar data dibaca ulang (dipakai setelah mendapat lock). */
function fresh_(name) {
  delete tableCache_[name];
  return table_(name);
}

function insert_(name, obj) {
  const t = table_(name);
  const row = t.sh.getLastRow() + 1;
  if (row > t.sh.getMaxRows()) t.sh.insertRowsAfter(t.sh.getMaxRows(), 100);
  const vals = t.head.map(k => (obj[k] === undefined || obj[k] === null ? '' : String(obj[k])));
  t.sh.getRange(row, 1, 1, vals.length).setNumberFormat('@').setValues([vals]);
  const o = { _row: row };
  t.head.forEach((k, j) => { o[k] = vals[j]; });
  t.rows.push(o);
  return o;
}

/** Mengubah beberapa kolom satu baris dalam satu kali tulis (lebih cepat daripada per sel). */
function update_(name, rec, changes) {
  const t = table_(name);
  const cols = Object.keys(changes).map(k => {
    const col = t.head.indexOf(k);
    if (col < 0) throw new Error('Kolom ' + k + ' tidak ada di sheet ' + name);
    rec[k] = changes[k] === undefined || changes[k] === null ? '' : String(changes[k]);
    return col;
  });
  if (!cols.length) return rec;
  const from = Math.min.apply(null, cols), to = Math.max.apply(null, cols);
  const vals = t.head.slice(from, to + 1).map(k => rec[k]);
  t.sh.getRange(rec._row, from + 1, 1, vals.length).setNumberFormat('@').setValues([vals]);
  return rec;
}

function deleteRow_(name, rec) {
  table_(name).sh.deleteRow(rec._row);
  delete tableCache_[name];
}

function newId_(prefix) {
  return prefix + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
}

/** Mencatat log tanpa membaca isi sheet Log (sheet ini terus bertambah besar). */
function log_(user, aksi, detail) {
  ss_().getSheetByName('Log').appendRow([
    now_().stamp, user ? user.id_user : '', user ? user.nama : '', aksi, detail || '',
  ]);
}

// ---------------------------------------------------------------- Waktu & lokasi

function now_() {
  const d = new Date();
  return {
    date: Utilities.formatDate(d, TZ, 'yyyy-MM-dd'),
    time: Utilities.formatDate(d, TZ, 'HH:mm'),
    stamp: Utilities.formatDate(d, TZ, 'yyyy-MM-dd HH:mm:ss'),
  };
}

function isDate_(s) { return /^\d{4}-\d{2}-\d{2}$/.test(String(s || '')); }
function isTime_(s) { return /^([01]\d|2[0-3]):[0-5]\d$/.test(String(s || '')); }

/** Jarak dua titik GPS dalam meter (rumus haversine). */
function distanceM_(lat1, lng1, lat2, lng2) {
  const R = 6371000;
  const rad = x => x * Math.PI / 180;
  const dLat = rad(lat2 - lat1);
  const dLng = rad(lng2 - lng1);
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2;
  return Math.round(2 * R * Math.asin(Math.sqrt(a)));
}

// ---------------------------------------------------------------- Drive

function rootFolder_() {
  const id = PropertiesService.getScriptProperties().getProperty('ROOT_FOLDER_ID');
  if (!id) throw new Error('LMS belum di-setup. Jalankan fungsi setup() di editor Apps Script.');
  return DriveApp.getFolderById(id);
}

function subfolder_(parent, name) {
  const it = parent.getFoldersByName(name);
  return it.hasNext() ? it.next() : parent.createFolder(name);
}

/** Menyimpan gambar data URL (data:image/...;base64,...) ke folder Drive. Mengembalikan file ID. */
function saveDataUrl_(folder, dataUrl, filename) {
  const m = /^data:(image\/(?:jpeg|png));base64,(.+)$/.exec(String(dataUrl || ''));
  if (!m) throw new Error('Format gambar tidak valid.');
  if (m[2].length > 3 * 1024 * 1024) throw new Error('Ukuran gambar terlalu besar.');
  const blob = Utilities.newBlob(Utilities.base64Decode(m[2]), m[1], filename);
  return folder.createFile(blob).getId();
}

/** Membuat file dapat dilihat siapa saja yang punya link (untuk materi & dokumentasi, bukan foto absen). */
function shareView_(file) {
  try {
    file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
  } catch (err) {
    console.warn('Gagal mengatur berbagi file', err);
  }
  return file;
}

function trashFile_(fileId) {
  if (!fileId) return;
  try {
    DriveApp.getFileById(fileId).setTrashed(true);
  } catch (err) {
    console.warn('Gagal menghapus file', fileId, err);
  }
}

function fileDataUrl_(fileId) {
  if (!fileId) return '';
  const blob = DriveApp.getFileById(fileId).getBlob();
  return 'data:' + blob.getContentType() + ';base64,' + Utilities.base64Encode(blob.getBytes());
}
