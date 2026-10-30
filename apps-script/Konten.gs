/** Konten harian: rundown, materi, dan dokumentasi kegiatan. */

const MATERI_EXT = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
const MATERI_MAX_MB = 20;

/** Hari yang boleh diakses: PIC batch tersebut, atau peserta di batch tersebut. */
function hariForUser_(user, idHari) {
  const hari = getHari_(idHari);
  if (isPic_(user)) batchForPic_(user, hari.id_batch);
  else if (user.id_batch !== hari.id_batch) throw new Error('Data ini bukan untuk batch Anda.');
  return hari;
}

function folderKonten_(batch, jenis, hari) {
  return subfolder_(subfolder_(DriveApp.getFolderById(batch.folder_id), jenis), 'Hari-' + hari.hari_ke);
}

function deleteRows_(name, rows) {
  rows.slice().sort((a, b) => b._row - a._row).forEach(r => deleteRow_(name, r));
}

// ---------------------------------------------------------------- Rundown

function rundownHari_(idHari) {
  return table_('Rundown').rows
    .filter(r => r.id_hari === idHari)
    .sort((a, b) => Number(a.urutan) - Number(b.urutan));
}

function rundownView_(r) {
  return {
    id_rundown: r.id_rundown, urutan: r.urutan, jam_mulai: r.jam_mulai, jam_selesai: r.jam_selesai,
    kegiatan: r.kegiatan, pemateri: r.pemateri, keterangan: r.keterangan,
  };
}

function rundownGet(data, user) {
  const hari = hariForUser_(user, data.id_hari);
  const rows = rundownHari_(hari.id_hari);
  return {
    status: !rows.length ? 'kosong' : rows.some(r => r.status === 'terbit') ? 'terbit' : 'draft',
    items: rows.map(rundownView_),
  };
}

/** Menyimpan seluruh rundown satu hari sekaligus (menggantikan yang lama). status: 'draft' | 'terbit'. */
function rundownSimpan(data, user) {
  const hari = hariForUser_(user, data.id_hari);
  const status = data.status === 'terbit' ? 'terbit' : 'draft';
  const items = (data.items || []).map((it, i) => ({
    jam_mulai: String(it.jam_mulai || '').trim(), jam_selesai: String(it.jam_selesai || '').trim(),
    kegiatan: String(it.kegiatan || '').trim(), pemateri: String(it.pemateri || '').trim(),
    keterangan: String(it.keterangan || '').trim(), _i: i,
  })).filter(it => it.kegiatan || it.jam_mulai || it.jam_selesai);
  items.forEach((it, i) => {
    const no = 'Baris ' + (i + 1) + ': ';
    if (!it.kegiatan) throw new Error(no + 'nama kegiatan wajib diisi.');
    if (!isTime_(it.jam_mulai) || !isTime_(it.jam_selesai)) throw new Error(no + 'jam mulai/selesai wajib diisi (JJ:MM).');
    if (it.jam_selesai <= it.jam_mulai) throw new Error(no + 'jam selesai harus setelah jam mulai.');
  });
  items.sort((a, b) => a.jam_mulai.localeCompare(b.jam_mulai) || a._i - b._i);

  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    fresh_('Rundown');
    const lama = rundownHari_(hari.id_hari);
    // Pertahankan id kegiatan yang sama agar foto dokumentasi yang terkait tidak kehilangan kaitannya.
    const sisaId = lama.map(r => r.id_rundown);
    deleteRows_('Rundown', lama);
    items.forEach((it, i) => {
      const lamaSama = lama.find(r => r.kegiatan === it.kegiatan && sisaId.indexOf(r.id_rundown) >= 0);
      const id = lamaSama ? lamaSama.id_rundown : newId_('r');
      if (lamaSama) sisaId.splice(sisaId.indexOf(id), 1);
      insert_('Rundown', {
        id_rundown: id, id_batch: hari.id_batch, id_hari: hari.id_hari, urutan: i + 1,
        jam_mulai: it.jam_mulai, jam_selesai: it.jam_selesai, kegiatan: it.kegiatan,
        pemateri: it.pemateri, keterangan: it.keterangan, status: status,
      });
    });
  } finally {
    lock.releaseLock();
  }
  log_(user, 'rundown_' + status, 'hari ' + hari.hari_ke + ': ' + items.length + ' kegiatan');
  return rundownGet({ id_hari: hari.id_hari }, user);
}

/** Menyalin rundown dari hari lain sebagai draft (bisa dari batch lain milik PIC yang sama). */
function rundownSalin(data, user) {
  const asal = hariForUser_(user, data.id_hari_asal);
  const items = rundownHari_(asal.id_hari).map(rundownView_);
  if (!items.length) throw new Error('Rundown hari asal masih kosong.');
  return rundownSimpan({ id_hari: data.id_hari, items: items, status: 'draft' }, user);
}

// ---------------------------------------------------------------- Materi

function materiView_(m) {
  const file = !!m.file_id;
  return {
    id_materi: m.id_materi, judul: m.judul, kategori: m.kategori, tipe_file: m.tipe_file, tampil_mulai: m.tampil_mulai,
    buka: file ? 'https://drive.google.com/file/d/' + m.file_id + '/view' : m.link,
    unduh: file ? 'https://drive.google.com/uc?export=download&id=' + m.file_id : '',
  };
}

function materiHari_(idHari) {
  return table_('Materi').rows
    .filter(m => m.id_hari === idHari)
    .sort((a, b) => a.kategori.localeCompare(b.kategori) || a.diupload_pada.localeCompare(b.diupload_pada));
}

/** Materi tampil ke peserta mulai tanggal harinya, dan mulai jam tampil_mulai jika diisi. */
function materiTerlihat_(m, hari, now) {
  if (hari.tanggal < now.date) return true;
  if (hari.tanggal > now.date) return false;
  return !m.tampil_mulai || now.time >= m.tampil_mulai;
}

function materiList(data, user) {
  const hari = hariForUser_(user, data.id_hari);
  return materiHari_(hari.id_hari).map(materiView_);
}

/**
 * Menambah materi berupa file (data: {nama, data: data URL base64}) atau link (https://...).
 * kategori: 'materi' | 'latihan'. tampil_mulai: '' atau 'JJ:MM'.
 */
function materiTambah(data, user) {
  const hari = hariForUser_(user, data.id_hari);
  const batch = getBatch_(hari.id_batch);
  const kategori = data.kategori === 'latihan' ? 'latihan' : 'materi';
  const tampil = String(data.tampil_mulai || '');
  if (tampil && !isTime_(tampil)) throw new Error('Jam tampil tidak valid (JJ:MM).');

  let fileId = '', link = '', tipe = 'link';
  let judul = String(data.judul || '').trim();
  if (data.file) {
    const nama = String(data.file.nama || 'materi');
    tipe = (nama.split('.').pop() || '').toLowerCase();
    if (MATERI_EXT.indexOf(tipe) < 0) throw new Error('Jenis file tidak didukung. Gunakan: ' + MATERI_EXT.join(', '));
    const m = /^data:([^;]*);base64,(.+)$/.exec(String(data.file.data || ''));
    if (!m) throw new Error('File tidak terbaca. Coba pilih ulang file.');
    const bytes = Utilities.base64Decode(m[2]);
    if (bytes.length > MATERI_MAX_MB * 1024 * 1024) {
      throw new Error('File lebih dari ' + MATERI_MAX_MB + ' MB. Simpan sebagai PDF atau upload ke Google Drive lalu gunakan link.');
    }
    const blob = Utilities.newBlob(bytes, m[1] || 'application/octet-stream', nama);
    fileId = shareView_(folderKonten_(batch, 'Materi', hari).createFile(blob)).getId();
    if (!judul) judul = nama.replace(/\.[^.]+$/, '');
  } else {
    link = String(data.link || '').trim();
    if (!/^https:\/\/\S+$/.test(link)) throw new Error('Pilih file atau isi link yang diawali https://');
    if (!judul) throw new Error('Judul wajib diisi untuk materi berupa link.');
  }

  const m = insert_('Materi', {
    id_materi: newId_('m'), id_batch: hari.id_batch, id_hari: hari.id_hari, judul: judul, kategori: kategori,
    file_id: fileId, tipe_file: tipe, link: link, tampil_mulai: tampil, diupload_oleh: user.nama, diupload_pada: now_().stamp,
  });
  log_(user, 'materi_tambah', 'hari ' + hari.hari_ke + ': ' + judul);
  return materiView_(m);
}

function materiHapus(data, user) {
  const m = table_('Materi').rows.find(r => r.id_materi === data.id_materi);
  if (!m) throw new Error('Materi tidak ditemukan.');
  hariForUser_(user, m.id_hari);
  trashFile_(m.file_id);
  deleteRow_('Materi', m);
  log_(user, 'materi_hapus', m.judul);
  return true;
}

// ---------------------------------------------------------------- Dokumentasi

function dokView_(d) {
  return {
    id_dok: d.id_dok, id_rundown: d.id_rundown, keterangan: d.keterangan, masuk_laporan: d.masuk_laporan === 'ya',
    thumb: 'https://drive.google.com/thumbnail?id=' + d.file_id + '&sz=w600',
    buka: 'https://drive.google.com/file/d/' + d.file_id + '/view',
  };
}

function dokHari_(idHari) {
  return table_('Dokumentasi').rows
    .filter(d => d.id_hari === idHari)
    .sort((a, b) => a.diupload_pada.localeCompare(b.diupload_pada));
}

function dokList(data, user) {
  const hari = hariForUser_(user, data.id_hari);
  return dokHari_(hari.id_hari).map(dokView_);
}

/** Menambah satu foto dokumentasi (sudah dikompres di browser). Untuk banyak foto, dipanggil berulang. */
function dokTambah(data, user) {
  const hari = hariForUser_(user, data.id_hari);
  const batch = getBatch_(hari.id_batch);
  if (data.id_rundown && !rundownHari_(hari.id_hari).some(r => r.id_rundown === data.id_rundown)) {
    throw new Error('Kegiatan rundown tidak ditemukan.');
  }
  const nama = 'H' + hari.hari_ke + '_dok_' + Utilities.formatDate(new Date(), TZ, 'HHmmss') + '_' + Math.random().toString(36).slice(2, 6) + '.jpg';
  const fileId = saveDataUrl_(folderKonten_(batch, 'Dokumentasi', hari), data.foto, nama);
  shareView_(DriveApp.getFileById(fileId));
  const d = insert_('Dokumentasi', {
    id_dok: newId_('d'), id_batch: hari.id_batch, id_hari: hari.id_hari, id_rundown: data.id_rundown || '',
    file_id: fileId, keterangan: String(data.keterangan || '').trim(), masuk_laporan: 'ya',
    diupload_oleh: user.nama, diupload_pada: now_().stamp,
  });
  return dokView_(d);
}

function dokForPic_(user, idDok) {
  const d = table_('Dokumentasi').rows.find(r => r.id_dok === idDok);
  if (!d) throw new Error('Foto tidak ditemukan.');
  hariForUser_(user, d.id_hari);
  return d;
}

function dokUbah(data, user) {
  const d = dokForPic_(user, data.id_dok);
  const changes = {};
  if (data.keterangan !== undefined) changes.keterangan = String(data.keterangan).trim();
  if (data.masuk_laporan !== undefined) changes.masuk_laporan = data.masuk_laporan ? 'ya' : 'tidak';
  if (data.id_rundown !== undefined) changes.id_rundown = data.id_rundown || '';
  update_('Dokumentasi', d, changes);
  return dokView_(d);
}

function dokHapus(data, user) {
  const d = dokForPic_(user, data.id_dok);
  trashFile_(d.file_id);
  deleteRow_('Dokumentasi', d);
  log_(user, 'dok_hapus', d.id_dok);
  return true;
}

// ---------------------------------------------------------------- Tampilan peserta

/**
 * Semua konten batch untuk peserta:
 * - rundown yang sudah diterbitkan (semua hari, agar peserta tahu jadwal ke depan)
 * - materi yang sudah waktunya tampil
 * - dokumentasi sampai hari ini
 */
function kontenPeserta(data, user) {
  wajibPeserta_(user);
  const batch = getBatch_(user.id_batch);
  const now = now_();
  return {
    tanggal: now.date,
    jam: now.time,
    hari: hariBatch_(batch.id_batch).map(h => ({
      id_hari: h.id_hari, hari_ke: h.hari_ke, tanggal: h.tanggal,
      rundown: rundownHari_(h.id_hari).filter(r => r.status === 'terbit').map(rundownView_),
      materi: materiHari_(h.id_hari).filter(m => materiTerlihat_(m, h, now)).map(materiView_),
      dok: h.tanggal <= now.date ? dokHari_(h.id_hari).map(dokView_) : [],
    })),
  };
}
