/** Batch, hari pelatihan, lokasi, dan data peserta. */

const DEFAULT_RADIUS = 150;

function getBatch_(idBatch) {
  const b = table_('Batch').rows.find(r => r.id_batch === idBatch);
  if (!b) throw new Error('Batch tidak ditemukan.');
  return b;
}

function canAccessBatch_(user, batch) {
  if (user.role === 'admin') return true;
  if (user.role === 'pic') return batch.pic_ids.split(',').indexOf(user.id_user) >= 0;
  return user.id_batch === batch.id_batch;
}

/** Mengambil batch dan memastikan PIC berhak mengelolanya. */
function batchForPic_(user, idBatch) {
  const b = getBatch_(idBatch);
  if (!canAccessBatch_(user, b)) throw new Error('Anda bukan PIC batch ini.');
  return b;
}

function batchView_(b) {
  const peserta = table_('Pengguna').rows.filter(u => u.role === 'peserta' && u.id_batch === b.id_batch && u.aktif !== 'tidak');
  const pics = table_('Pengguna').rows.filter(u => b.pic_ids.split(',').indexOf(u.id_user) >= 0);
  const out = {};
  SHEETS.Batch.forEach(k => { out[k] = b[k]; });
  out.jumlah_peserta = peserta.length;
  out.pic_nama = pics.map(p => p.nama);
  out.folder_url = b.folder_id ? 'https://drive.google.com/drive/folders/' + b.folder_id : '';
  return out;
}

function batchList(data, user) {
  return table_('Batch').rows
    .filter(b => canAccessBatch_(user, b))
    .sort((a, b) => (a.status === b.status ? b.tgl_mulai.localeCompare(a.tgl_mulai) : a.status === 'aktif' ? -1 : 1))
    .map(batchView_);
}

function validasiBatch_(d) {
  if (!String(d.nama_pelatihan || '').trim()) throw new Error('Nama pelatihan wajib diisi.');
  if (!isDate_(d.tgl_mulai) || !isDate_(d.tgl_selesai)) throw new Error('Tanggal mulai/selesai tidak valid.');
  if (d.tgl_selesai < d.tgl_mulai) throw new Error('Tanggal selesai tidak boleh sebelum tanggal mulai.');
  ['sesi1_mulai', 'sesi1_selesai', 'sesi2_mulai', 'sesi2_selesai'].forEach(k => {
    if (!isTime_(d[k])) throw new Error('Jam sesi tidak valid (format JJ:MM).');
  });
  if (d.sesi1_selesai <= d.sesi1_mulai || d.sesi2_selesai <= d.sesi2_mulai) throw new Error('Jam selesai sesi harus setelah jam mulai.');
  if (d.sesi2_mulai < d.sesi1_selesai) throw new Error('Sesi 2 harus dimulai setelah Sesi 1 selesai.');
  validasiLokasi_(d, true);
}

function validasiLokasi_(d, optional) {
  const empty = d.lat === '' || d.lat === undefined || d.lat === null;
  if (empty && optional) return;
  const lat = Number(d.lat), lng = Number(d.lng), r = Number(d.radius_meter || DEFAULT_RADIUS);
  if (!isFinite(lat) || !isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180 || empty) {
    throw new Error('Koordinat lokasi tidak valid.');
  }
  if (!(r >= 20 && r <= 5000)) throw new Error('Radius harus antara 20 dan 5000 meter.');
}

function batchBuat(data, user) {
  const d = Object.assign({
    sesi1_mulai: '08:00', sesi1_selesai: '12:00', sesi2_mulai: '13:00', sesi2_selesai: '17:00',
    radius_meter: DEFAULT_RADIUS,
  }, data.batch || {});
  validasiBatch_(d);
  const kode = String(d.kode_batch || '').trim();
  if (!kode) throw new Error('Kode batch wajib diisi (mis. B07).');
  if (table_('Batch').rows.some(b => b.kode_batch.toLowerCase() === kode.toLowerCase())) {
    throw new Error('Kode batch sudah dipakai.');
  }
  const folder = rootFolder_().createFolder(kode + ' - ' + String(d.nama_pelatihan).trim());
  ['Absensi', 'Materi', 'Dokumentasi', 'Laporan'].forEach(n => folder.createFolder(n));

  const b = insert_('Batch', {
    id_batch: newId_('b'),
    nama_pelatihan: String(d.nama_pelatihan).trim(),
    kode_batch: kode,
    lokasi_nama: d.lokasi_nama || '',
    tgl_mulai: d.tgl_mulai, tgl_selesai: d.tgl_selesai,
    sesi1_mulai: d.sesi1_mulai, sesi1_selesai: d.sesi1_selesai,
    sesi2_mulai: d.sesi2_mulai, sesi2_selesai: d.sesi2_selesai,
    lat: d.lat === undefined ? '' : d.lat, lng: d.lng === undefined ? '' : d.lng,
    radius_meter: d.radius_meter,
    lewati_weekend: d.lewati_weekend ? 'ya' : '',
    pic_ids: (d.pic_ids || []).join(','),
    folder_id: folder.getId(),
    status: 'aktif',
    dibuat_pada: now_().stamp,
  });
  syncHari_(b);
  log_(user, 'batch_buat', kode);
  return batchView_(b);
}

/**
 * Mengubah pengaturan batch. PIC boleh mengubah jam sesi & lokasi default;
 * Admin juga boleh mengubah nama, tanggal, PIC, dan status.
 */
function batchUbah(data, user) {
  const b = batchForPic_(user, data.id_batch);
  const d = data.batch || {};
  const editable = ['sesi1_mulai', 'sesi1_selesai', 'sesi2_mulai', 'sesi2_selesai', 'lokasi_nama', 'lat', 'lng', 'radius_meter'];
  if (user.role === 'admin') editable.push('nama_pelatihan', 'tgl_mulai', 'tgl_selesai', 'status');
  const changes = {};
  editable.forEach(k => { if (d[k] !== undefined) changes[k] = d[k]; });
  if (user.role === 'admin' && Array.isArray(d.pic_ids)) changes.pic_ids = d.pic_ids.join(',');
  if (user.role === 'admin' && d.lewati_weekend !== undefined) changes.lewati_weekend = d.lewati_weekend ? 'ya' : '';
  if (changes.status && ['aktif', 'selesai'].indexOf(changes.status) < 0) throw new Error('Status tidak valid.');

  validasiBatch_(Object.assign({}, b, changes));
  const hariBerubah = ['tgl_mulai', 'tgl_selesai', 'lewati_weekend'].some(k => changes[k] !== undefined && changes[k] !== b[k]);
  update_('Batch', b, changes);
  if (hariBerubah) syncHari_(b);
  log_(user, 'batch_ubah', b.kode_batch + ': ' + Object.keys(changes).join(', '));
  return batchView_(b);
}

/** Menyamakan daftar hari pelatihan dengan rentang tanggal batch. Hari yang sudah ada absensinya tidak dihapus. */
function syncHari_(batch) {
  const lewatiWeekend = batch.lewati_weekend === 'ya';
  const want = [];
  const d = new Date(batch.tgl_mulai + 'T00:00:00Z');
  const end = new Date(batch.tgl_selesai + 'T00:00:00Z');
  for (; d <= end; d.setUTCDate(d.getUTCDate() + 1)) {
    const dow = d.getUTCDay();
    if (lewatiWeekend && (dow === 0 || dow === 6)) continue;
    want.push(d.toISOString().slice(0, 10));
  }
  if (want.length > 90) throw new Error('Rentang tanggal terlalu panjang (maks. 90 hari).');

  const absen = table_('Absensi').rows;
  hariBatch_(batch.id_batch)
    .filter(h => want.indexOf(h.tanggal) < 0 && !absen.some(a => a.id_hari === h.id_hari))
    .sort((a, b) => b._row - a._row) // hapus dari bawah agar nomor baris tidak bergeser
    .forEach(h => deleteRow_('Hari', h));

  const ada = hariBatch_(batch.id_batch).map(h => h.tanggal);
  want.filter(t => ada.indexOf(t) < 0).forEach(t => {
    insert_('Hari', { id_hari: newId_('h'), id_batch: batch.id_batch, tanggal: t });
  });
  hariBatch_(batch.id_batch).forEach((h, i) => {
    if (h.hari_ke !== String(i + 1)) update_('Hari', h, { hari_ke: i + 1 });
  });
}

function hariBatch_(idBatch) {
  return table_('Hari').rows
    .filter(h => h.id_batch === idBatch)
    .sort((a, b) => a.tanggal.localeCompare(b.tanggal));
}

function getHari_(idHari) {
  const h = table_('Hari').rows.find(r => r.id_hari === idHari);
  if (!h) throw new Error('Hari pelatihan tidak ditemukan.');
  return h;
}

/** Lokasi yang berlaku untuk suatu hari: lokasi khusus hari itu, atau lokasi default batch. */
function lokasiHari_(batch, hari) {
  const khusus = hari && hari.lat !== '';
  const src = khusus ? hari : batch;
  return {
    khusus: !!khusus,
    nama: src.lokasi_nama || (khusus ? 'Lokasi khusus' : 'Lokasi pelatihan'),
    lat: src.lat === '' ? null : Number(src.lat),
    lng: src.lng === '' ? null : Number(src.lng),
    radius: Number(src.radius_meter || DEFAULT_RADIUS),
  };
}

function hariList(data, user) {
  const b = batchForPic_(user, data.id_batch);
  return hariBatch_(b.id_batch).map(h => ({
    id_hari: h.id_hari, tanggal: h.tanggal, hari_ke: h.hari_ke, lokasi: lokasiHari_(b, h),
  }));
}

function hariSetLokasi(data, user) {
  const h = getHari_(data.id_hari);
  const b = batchForPic_(user, h.id_batch);
  validasiLokasi_(data, false);
  update_('Hari', h, {
    lokasi_nama: String(data.lokasi_nama || '').trim(),
    lat: Number(data.lat), lng: Number(data.lng),
    radius_meter: Number(data.radius_meter || DEFAULT_RADIUS),
    diubah_oleh: user.nama,
  });
  log_(user, 'lokasi_hari', b.kode_batch + ' hari ' + h.hari_ke + ' → ' + h.lokasi_nama + ' (' + h.lat + ', ' + h.lng + ', r=' + h.radius_meter + ')');
  return lokasiHari_(b, h);
}

function hariResetLokasi(data, user) {
  const h = getHari_(data.id_hari);
  const b = batchForPic_(user, h.id_batch);
  update_('Hari', h, { lokasi_nama: '', lat: '', lng: '', radius_meter: '', diubah_oleh: user.nama });
  log_(user, 'lokasi_hari_reset', b.kode_batch + ' hari ' + h.hari_ke);
  return lokasiHari_(b, h);
}

/** Mengambil koordinat dari teks "lat, lng" atau link Google Maps (termasuk link pendek maps.app.goo.gl). */
function lokasiDariTeks(data) {
  let teks = String(data.teks || '').trim();
  if (/^https?:\/\/(maps\.app\.goo\.gl|goo\.gl)\//.test(teks)) {
    for (let i = 0; i < 5; i++) {
      const res = UrlFetchApp.fetch(teks, { followRedirects: false, muteHttpExceptions: true });
      const loc = res.getHeaders()['Location'] || res.getHeaders()['location'];
      if (!loc) break;
      teks = decodeURIComponent(loc);
    }
  }
  const pola = [
    /@(-?\d+\.\d+),(-?\d+\.\d+)/,
    /!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/,
    /[?&](?:q|query|ll|center|destination)=(-?\d+\.\d+),\s*(-?\d+\.\d+)/,
    /^(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)$/,
    /(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/,
  ];
  for (let i = 0; i < pola.length; i++) {
    const m = pola[i].exec(teks);
    if (m) return { lat: Number(m[1]), lng: Number(m[2]) };
  }
  throw new Error('Koordinat tidak ditemukan. Tempel link Google Maps atau koordinat seperti -6.2088, 106.8456');
}

// ---------------------------------------------------------------- Peserta

function pesertaBatch_(idBatch, termasukNonaktif) {
  return table_('Pengguna').rows
    .filter(u => u.role === 'peserta' && u.id_batch === idBatch && (termasukNonaktif || u.aktif !== 'tidak'))
    .sort((a, b) => Number(a.no_absen) - Number(b.no_absen));
}

function pesertaList(data, user) {
  const b = batchForPic_(user, data.id_batch);
  return pesertaBatch_(b.id_batch, true).map(u => ({
    id_user: u.id_user, no_absen: u.no_absen, nama: u.nama, kode_login: u.kode_login,
    instansi: u.instansi, no_hp: u.no_hp, hp_terhubung: !!u.device_id, aktif: u.aktif !== 'tidak',
  }));
}

function namaDepan_(nama) {
  const w = String(nama).trim().split(/\s+/)[0].replace(/[^A-Za-z0-9]/g, '');
  return w ? w.charAt(0).toUpperCase() + w.slice(1).toLowerCase() : 'Peserta';
}

/**
 * Menambah peserta dari teks: satu peserta per baris.
 * Kolom dipisah Tab (hasil copy dari Excel) atau titik koma: Nama ; Instansi ; No HP
 * Kode login dibuat otomatis: nomor absen 3 digit + nama depan, mis. 001-Kikis.
 */
function pesertaTambah(data, user) {
  const b = batchForPic_(user, data.id_batch);
  const lines = String(data.teks || '').split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  if (!lines.length) throw new Error('Isi nama peserta, satu nama per baris.');

  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    fresh_('Pengguna');
    let no = pesertaBatch_(b.id_batch, true).reduce((m, u) => Math.max(m, Number(u.no_absen) || 0), 0);
    const added = [];
    lines.forEach(line => {
      const cols = line.split(/\t|;/).map(c => c.trim());
      const nama = cols[0].replace(/\s+/g, ' ');
      if (!nama || /^(no\.?|nama( lengkap)?)$/i.test(nama)) return; // lewati baris judul
      no++;
      const kode = ('00' + no).slice(-3) + '-' + namaDepan_(nama);
      const u = insert_('Pengguna', {
        id_user: newId_('u'), id_batch: b.id_batch, no_absen: no, nama: nama, role: 'peserta',
        kode_login: kode, instansi: cols[1] || '', no_hp: cols[2] || '', aktif: 'ya', dibuat_pada: now_().stamp,
      });
      added.push({ no_absen: u.no_absen, nama: u.nama, kode_login: u.kode_login });
    });
    log_(user, 'peserta_tambah', b.kode_batch + ': ' + added.length + ' peserta');
    return added;
  } finally {
    lock.releaseLock();
  }
}

function pesertaForPic_(user, idUser) {
  const u = table_('Pengguna').rows.find(r => r.id_user === idUser && r.role === 'peserta');
  if (!u) throw new Error('Peserta tidak ditemukan.');
  batchForPic_(user, u.id_batch);
  return u;
}

/** Mengubah data peserta. Kode login tetap, agar kartu kode yang sudah dibagikan tetap berlaku. */
function pesertaUbah(data, user) {
  const u = pesertaForPic_(user, data.id_user);
  const nama = String(data.nama || '').trim().replace(/\s+/g, ' ');
  if (!nama) throw new Error('Nama wajib diisi.');
  update_('Pengguna', u, { nama: nama, instansi: data.instansi || '', no_hp: data.no_hp || '' });
  log_(user, 'peserta_ubah', u.kode_login);
  return true;
}

function pesertaResetDevice(data, user) {
  const u = pesertaForPic_(user, data.id_user);
  update_('Pengguna', u, { device_id: '', token: '' });
  log_(user, 'peserta_reset_hp', u.kode_login + ' ' + u.nama);
  return true;
}

function pesertaAktif(data, user) {
  const u = pesertaForPic_(user, data.id_user);
  update_('Pengguna', u, data.aktif ? { aktif: 'ya' } : { aktif: 'tidak', token: '' });
  log_(user, data.aktif ? 'peserta_aktifkan' : 'peserta_nonaktifkan', u.kode_login + ' ' + u.nama);
  return true;
}
