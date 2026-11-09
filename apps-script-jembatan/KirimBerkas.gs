/**
 * Jembatan Google Drive untuk LMS Artha Safety Indonesia.
 * HANYA untuk mengirim berkas peserta (persyaratan & asesmen) ke folder Google Drive lembaga/LSP.
 * Proses LMS lain tetap berjalan di Hostinger.
 *
 * Pasang dengan akun Google Artha (info.arthasafetyindonesia@gmail.com):
 *   1. script.google.com → Proyek baru → hapus isi Code.gs → tempel seluruh file ini.
 *   2. Ganti nilai RAHASIA di bawah dengan "Kode rahasia" dari LMS (menu Pustaka Pelatihan → Jembatan Google Drive).
 *   3. Terapkan → Deployment baru → Jenis: Aplikasi web → Jalankan sebagai: Saya → Yang memiliki akses: Siapa saja → Terapkan.
 *   4. Salin URL aplikasi web (…/exec) → tempel di LMS → Simpan → Cek jembatan.
 *
 * Struktur folder di Drive lembaga:  <link lembaga>/<Nama Peserta>/Persyaratan  dan  /Asesmen
 * Folder yang sudah dibuat PIC dipakai (nama dicocokkan tanpa membedakan huruf besar/kecil); yang belum ada dibuat otomatis.
 */
const RAHASIA = 'TEMPEL-KODE-RAHASIA-DARI-LMS';
const SUB_FOLDER = ['Persyaratan', 'Asesmen'];
const VERSI = 'jembatan-1';

function doPost(e) {
  try {
    const q = JSON.parse(e.postData.contents);
    if (q.secret !== RAHASIA) return jawab({ ok: false, error: 'Kode rahasia jembatan tidak cocok. Samakan RAHASIA di Apps Script dengan kode di LMS.' });
    if (q.action === 'ping') return jawab({ ok: true, akun: Session.getEffectiveUser().getEmail(), versi: VERSI });
    if (q.action === 'cek') return jawab(cekFolder(q.root));
    if (q.action === 'folders') return jawab(buatFolder(q.root, q.names || []));
    if (q.action === 'upload') return jawab(upload(q));
    return jawab({ ok: false, error: 'Aksi tidak dikenal: ' + q.action });
  } catch (err) {
    return jawab({ ok: false, error: pesanError(err) });
  }
}

function doGet() {
  return jawab({ ok: true, info: 'Jembatan Google Drive LMS Artha Safety aktif', versi: VERSI });
}

function cekFolder(rootId) {
  const root = DriveApp.getFolderById(rootId);
  let bisaTulis = false;
  try {
    const t = root.createFile('lms-cek-akses.txt', 'cek akses LMS');
    t.setTrashed(true);
    bisaTulis = true;
  } catch (e) { /* tidak punya hak edit */ }
  let n = 0;
  const it = root.getFolders();
  while (it.hasNext()) { it.next(); n++; }
  return { ok: true, nama: root.getName(), bisa_tulis: bisaTulis, jumlah_folder: n };
}

function buatFolder(rootId, names) {
  const root = DriveApp.getFolderById(rootId);
  const ada = petaFolder(root);
  let dibuat = 0, sudahAda = 0;
  names.forEach(function (n) {
    const kunci = String(n).trim().toLowerCase();
    let f = ada[kunci];
    if (f) sudahAda++; else { f = root.createFolder(String(n).trim()); ada[kunci] = f; dibuat++; }
    SUB_FOLDER.forEach(function (s) { subFolder(f, s); });
  });
  return { ok: true, dibuat: dibuat, sudah_ada: sudahAda };
}

function upload(q) {
  const root = DriveApp.getFolderById(q.root);
  const folder = folderPeserta(root, q.folder);
  const sub = subFolder(folder, q.sub || 'Persyaratan');
  if (q.replace) {
    try { DriveApp.getFileById(q.replace).setTrashed(true); } catch (e) { /* file lama sudah tidak ada / bukan milik akun ini */ }
  }
  const blob = Utilities.newBlob(Utilities.base64Decode(q.data), q.mime || 'application/octet-stream', q.name);
  const file = sub.createFile(blob);
  return { ok: true, id: file.getId(), url: file.getUrl() };
}

/** Folder "<Nama Peserta>" di dalam folder lembaga, beserta subfolder Persyaratan & Asesmen. */
function folderPeserta(root, nama) {
  const kunci = String(nama).trim().toLowerCase();
  const it = root.getFolders();
  let f = null;
  while (it.hasNext()) { const x = it.next(); if (x.getName().trim().toLowerCase() === kunci) { f = x; break; } }
  if (!f) f = root.createFolder(String(nama).trim());
  SUB_FOLDER.forEach(function (s) { subFolder(f, s); });
  return f;
}

function subFolder(parent, nama) {
  const it = parent.getFolders();
  while (it.hasNext()) { const x = it.next(); if (x.getName().trim().toLowerCase() === nama.toLowerCase()) return x; }
  return parent.createFolder(nama);
}

function petaFolder(root) {
  const out = {};
  const it = root.getFolders();
  while (it.hasNext()) { const x = it.next(); out[x.getName().trim().toLowerCase()] = x; }
  return out;
}

function pesanError(err) {
  const m = String((err && err.message) || err);
  if (/not found|tidak ditemukan|No item with the given ID/i.test(m)) return 'Folder Google Drive tidak ditemukan atau akun Artha tidak punya akses. Periksa link folder lembaga.';
  if (/storage|quota|kuota/i.test(m)) return 'Kuota penyimpanan Google Drive akun Artha penuh.';
  if (/permission|access|izin/i.test(m)) return 'Akun Artha tidak punya izin edit di folder lembaga. Pastikan link folder: Siapa saja yang memiliki link → Editor.';
  return m;
}

function jawab(o) {
  return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
}
