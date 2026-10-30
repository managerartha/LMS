/**
 * Setup awal — jalankan SEKALI dari editor Apps Script (pilih fungsi "setup" lalu klik Run).
 * Aman dijalankan ulang: yang sudah ada tidak dibuat dua kali.
 *
 * Membuat:
 *  - folder "LMS Artha Safety" di Google Drive
 *  - Spreadsheet "LMS Artha Safety - Database" berisi semua sheet
 *  - akun Admin pertama (kode ADMIN, PIN di bawah) — segera ganti PIN setelah login
 */
const ADMIN_PIN_AWAL = '123456';

function setup() {
  const props = PropertiesService.getScriptProperties();

  let root;
  const rootId = props.getProperty('ROOT_FOLDER_ID');
  if (rootId) {
    root = DriveApp.getFolderById(rootId);
  } else {
    root = DriveApp.createFolder(ROOT_FOLDER_NAME);
    props.setProperty('ROOT_FOLDER_ID', root.getId());
  }

  let ss;
  const dbId = props.getProperty('DB_ID');
  if (dbId) {
    ss = SpreadsheetApp.openById(dbId);
  } else {
    ss = SpreadsheetApp.create(DB_NAME);
    DriveApp.getFileById(ss.getId()).moveTo(root);
    props.setProperty('DB_ID', ss.getId());
  }
  ss.setSpreadsheetTimeZone(TZ);

  Object.keys(SHEETS).forEach(name => {
    const cols = SHEETS[name];
    let sh = ss.getSheetByName(name);
    if (!sh) sh = ss.insertSheet(name);
    // Tambah kolom yang belum ada (untuk pembaruan versi berikutnya).
    const lastCol = sh.getLastColumn();
    const existing = lastCol ? sh.getRange(1, 1, 1, lastCol).getDisplayValues()[0] : [];
    const missing = cols.filter(c => existing.indexOf(c) < 0);
    if (missing.length) {
      sh.getRange(1, existing.length + 1, 1, missing.length).setValues([missing]);
    }
    const width = existing.length + missing.length;
    // Semua isi disimpan sebagai teks agar tanggal/jam/kode tidak diubah otomatis oleh Sheets.
    sh.getRange(1, 1, sh.getMaxRows(), width).setNumberFormat('@');
    sh.getRange(1, 1, 1, width).setFontWeight('bold').setBackground('#0b3a6e').setFontColor('#ffffff');
    sh.setFrozenRows(1);
  });
  const def = ss.getSheetByName('Sheet1') || ss.getSheetByName('Sheet 1');
  if (def && ss.getSheets().length > 1) ss.deleteSheet(def);

  tableCache_ = {};
  const users = table_('Pengguna');
  if (!users.rows.some(u => u.role === 'admin')) {
    insert_('Pengguna', {
      id_user: newId_('u'),
      nama: 'Admin',
      role: 'admin',
      kode_login: 'ADMIN',
      pin_hash: hash_(ADMIN_PIN_AWAL),
      aktif: 'ya',
      dibuat_pada: now_().stamp,
    });
    console.log('Akun Admin dibuat. Kode: ADMIN, PIN: ' + ADMIN_PIN_AWAL + ' (segera ganti setelah login).');
  }

  console.log('Setup selesai.');
  console.log('Folder Drive : ' + root.getUrl());
  console.log('Spreadsheet  : ' + ss.getUrl());
}
