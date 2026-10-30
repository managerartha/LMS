/** Absensi: absen peserta, riwayat, monitor PIC, ubah status izin/sakit/alpa. */

const SESI = [1, 2];

function jamSesi_(batch, sesi) {
  return { mulai: batch['sesi' + sesi + '_mulai'], selesai: batch['sesi' + sesi + '_selesai'] };
}

/** 'belum_dibuka' | 'buka' | 'tutup' menurut jam server. */
function stateSesi_(batch, hari, sesi, now) {
  const j = jamSesi_(batch, sesi);
  if (hari.tanggal > now.date) return 'belum_dibuka';
  if (hari.tanggal < now.date) return 'tutup';
  if (now.time < j.mulai) return 'belum_dibuka';
  if (now.time >= j.selesai) return 'tutup';
  return 'buka';
}

function absenOf_(idHari, idUser, sesi) {
  return table_('Absensi').rows.find(a => a.id_hari === idHari && a.id_user === idUser && a.sesi === String(sesi));
}

/**
 * Status akhir satu sesi untuk satu peserta.
 * Tanpa catatan: 'belum' selama sesi buka, 'alpa' otomatis setelah sesi tutup, '' jika belum dibuka.
 */
function statusSesi_(rec, state) {
  if (rec) return rec.status;
  if (state === 'tutup') return 'alpa';
  if (state === 'buka') return 'belum';
  return '';
}

function absenView_(rec) {
  return rec ? {
    id_absen: rec.id_absen, waktu: rec.waktu_server, status: rec.status, keterangan: rec.keterangan,
    jarak_meter: rec.jarak_meter, ada_foto: !!rec.foto_id,
  } : null;
}

function hariIni_(batch, now) {
  return hariBatch_(batch.id_batch).find(h => h.tanggal === now.date) || null;
}

function wajibPeserta_(user) {
  if (user.role !== 'peserta') throw new Error('Menu ini khusus peserta.');
}

function statusHariIni(data, user) {
  wajibPeserta_(user);
  const batch = getBatch_(user.id_batch);
  const now = now_();
  const hari = hariIni_(batch, now);
  const semua = hariBatch_(batch.id_batch);
  const out = {
    server_now: Date.now(),
    tanggal: now.date,
    batch: {
      nama_pelatihan: batch.nama_pelatihan, kode_batch: batch.kode_batch, status: batch.status,
      tgl_mulai: batch.tgl_mulai, tgl_selesai: batch.tgl_selesai, lokasi_nama: batch.lokasi_nama,
    },
    hari: null,
    total_hari: semua.length,
    hari_selesai: semua.filter(h => h.tanggal < now.date).length,
    berikutnya: (semua.find(h => h.tanggal > now.date) || {}).tanggal || null,
    rekap: rekapPeserta_(batch, semua, user, now),
  };
  if (!hari) return out;
  const lok = lokasiHari_(batch, hari);
  out.hari = {
    id_hari: hari.id_hari, hari_ke: hari.hari_ke, total_hari: semua.length,
    lokasi: lok,
    sesi: SESI.map(s => {
      const state = stateSesi_(batch, hari, s, now);
      const rec = absenOf_(hari.id_hari, user.id_user, s);
      return Object.assign({ sesi: s, state: state, status: statusSesi_(rec, state), absen: absenView_(rec) }, jamSesi_(batch, s));
    }),
  };
  return out;
}

/** Jumlah sesi hadir dibanding sesi yang sudah berjalan (sesi yang masih dibuka & belum absen tidak dihitung). */
function rekapPeserta_(batch, hariList, user, now) {
  const r = { hadir: 0, izin: 0, sakit: 0, alpa: 0, total: 0 };
  hariList.filter(h => h.tanggal <= now.date).forEach(h => SESI.forEach(s => {
    const st = statusSesi_(absenOf_(h.id_hari, user.id_user, s), stateSesi_(batch, h, s, now));
    if (r[st] === undefined) return;
    r[st]++;
    r.total++;
  }));
  return r;
}

function absen(data, user) {
  wajibPeserta_(user);
  if (!user.setuju_privasi) throw new Error('Setujui pernyataan privasi terlebih dahulu.');
  const sesi = Number(data.sesi);
  if (SESI.indexOf(sesi) < 0) throw new Error('Sesi tidak valid.');
  const batch = getBatch_(user.id_batch);
  if (batch.status !== 'aktif') throw new Error('Batch sudah tidak aktif.');

  // 1. Cek jadwal & jam (pakai jam server).
  const now = now_();
  const hari = hariIni_(batch, now);
  if (!hari) throw new Error('Hari ini tidak ada jadwal pelatihan.');
  const j = jamSesi_(batch, sesi);
  if (stateSesi_(batch, hari, sesi, now) !== 'buka') {
    throw new Error('Absen Sesi ' + sesi + ' hanya bisa dilakukan pukul ' + j.mulai + '–' + j.selesai + ' WIB.');
  }

  // 2. Cek lokasi.
  const lok = lokasiHari_(batch, hari);
  if (lok.lat === null) throw new Error('Lokasi pelatihan hari ini belum diatur PIC. Hubungi PIC.');
  const lat = Number(data.lat), lng = Number(data.lng);
  if (data.lat === undefined || data.lat === null || !isFinite(lat) || !isFinite(lng)) {
    throw new Error('Lokasi GPS tidak terbaca. Aktifkan GPS/lokasi lalu coba lagi.');
  }
  const jarak = distanceM_(lat, lng, lok.lat, lok.lng);
  if (jarak > lok.radius) {
    throw new Error('Anda berada ± ' + jarak + ' m dari ' + lok.nama + '. Absen hanya bisa dalam radius ' + lok.radius + ' m.');
  }
  if (absenOf_(hari.id_hari, user.id_user, sesi) && absenOf_(hari.id_hari, user.id_user, sesi).status === 'hadir') {
    throw new Error('Anda sudah absen Sesi ' + sesi + ' hari ini.');
  }

  // 3. Simpan foto & tanda tangan ke Drive (di luar lock agar peserta lain tidak menunggu lama).
  const folder = subfolder_(subfolder_(DriveApp.getFolderById(batch.folder_id), 'Absensi'), 'Hari-' + hari.hari_ke);
  const base = 'H' + hari.hari_ke + '_S' + sesi + '_' + user.kode_login;
  const fotoId = saveDataUrl_(folder, data.foto, base + '_foto.jpg');
  const ttdId = saveDataUrl_(folder, data.ttd, base + '_ttd.png');

  // 4. Catat ke Spreadsheet (dengan lock agar tidak tercatat dobel).
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    fresh_('Absensi');
    const waktu = now_().stamp;
    const ada = absenOf_(hari.id_hari, user.id_user, sesi);
    const rec = {
      waktu_server: waktu, foto_id: fotoId, ttd_id: ttdId, lat: lat, lng: lng,
      akurasi_meter: Math.round(Number(data.akurasi) || 0), jarak_meter: jarak, status: 'hadir',
      keterangan: '', diubah_oleh: '', diubah_pada: '',
    };
    if (ada && ada.status === 'hadir') {
      DriveApp.getFileById(fotoId).setTrashed(true);
      DriveApp.getFileById(ttdId).setTrashed(true);
      throw new Error('Anda sudah absen Sesi ' + sesi + ' hari ini.');
    }
    if (ada) {
      update_('Absensi', ada, rec); // sebelumnya ditandai izin/sakit/alpa oleh PIC, peserta ternyata hadir
    } else {
      insert_('Absensi', Object.assign({
        id_absen: newId_('a'), id_batch: batch.id_batch, id_hari: hari.id_hari, id_user: user.id_user, sesi: sesi,
      }, rec));
    }
    return { waktu: waktu, jarak_meter: jarak };
  } finally {
    lock.releaseLock();
  }
}

function riwayat(data, user) {
  wajibPeserta_(user);
  const batch = getBatch_(user.id_batch);
  const now = now_();
  return hariBatch_(batch.id_batch).map(h => ({
    hari_ke: h.hari_ke, tanggal: h.tanggal,
    sesi: SESI.map(s => {
      const rec = absenOf_(h.id_hari, user.id_user, s);
      return { sesi: s, status: statusSesi_(rec, stateSesi_(batch, h, s, now)), waktu: rec ? rec.waktu_server : '', keterangan: rec ? rec.keterangan : '' };
    }),
  }));
}

// ---------------------------------------------------------------- PIC

/**
 * Rekap absensi satu hari untuk PIC. Bisa dipanggil dengan id_batch saja: hari dipilih otomatis
 * (hari ini, atau hari terdekat) dan daftar hari ikut dikirim, agar halaman PIC cukup satu permintaan.
 */
function monitor(data, user) {
  let hari, daftarHari = null;
  if (data.id_batch) {
    const b = batchForPic_(user, data.id_batch);
    const semua = hariBatch_(b.id_batch);
    const today = now_().date;
    hari = semua.find(h => h.id_hari === data.id_hari) || semua.find(h => h.tanggal === today) ||
      semua.find(h => h.tanggal > today) || semua[semua.length - 1];
    if (!hari) return { hariList: [], hari: null };
    daftarHari = hariList({ id_batch: b.id_batch }, user);
  } else {
    hari = getHari_(data.id_hari);
  }
  const batch = batchForPic_(user, hari.id_batch);
  const now = now_();
  const states = SESI.map(s => stateSesi_(batch, hari, s, now));
  const rekap = SESI.map(() => ({ hadir: 0, izin: 0, sakit: 0, alpa: 0, belum: 0 }));
  const peserta = pesertaBatch_(batch.id_batch, false).map(u => ({
    id_user: u.id_user, no_absen: u.no_absen, nama: u.nama, kode_login: u.kode_login,
    sesi: SESI.map((s, i) => {
      const rec = absenOf_(hari.id_hari, u.id_user, s);
      const status = statusSesi_(rec, states[i]);
      if (rekap[i][status] !== undefined) rekap[i][status]++;
      return Object.assign({ sesi: s, status: status }, absenView_(rec) || {});
    }),
  }));
  return {
    server_now: Date.now(),
    hariList: daftarHari,
    hari: { id_hari: hari.id_hari, hari_ke: hari.hari_ke, tanggal: hari.tanggal, lokasi: lokasiHari_(batch, hari) },
    sesi: SESI.map((s, i) => Object.assign({ sesi: s, state: states[i], rekap: rekap[i] }, jamSesi_(batch, s))),
    peserta: peserta,
  };
}

/** PIC menandai Izin / Sakit / Alpa. Foto & tanda tangan (jika ada) tetap disimpan sebagai arsip. */
function absenSetStatus(data, user) {
  const hari = getHari_(data.id_hari);
  const batch = batchForPic_(user, hari.id_batch);
  const u = pesertaForPic_(user, data.id_user);
  if (u.id_batch !== batch.id_batch) throw new Error('Peserta bukan anggota batch ini.');
  const sesi = Number(data.sesi);
  if (SESI.indexOf(sesi) < 0) throw new Error('Sesi tidak valid.');
  const status = String(data.status || '');
  if (['izin', 'sakit', 'alpa', 'hadir'].indexOf(status) < 0) throw new Error('Status tidak valid.');

  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    fresh_('Absensi');
    const ada = absenOf_(hari.id_hari, u.id_user, sesi);
    if (status === 'hadir' && !(ada && ada.foto_id)) {
      throw new Error('Status Hadir hanya dari absen peserta (foto + tanda tangan).');
    }
    const changes = { status: status, keterangan: String(data.keterangan || ''), diubah_oleh: user.nama, diubah_pada: now_().stamp };
    const lama = ada ? ada.status : '(kosong)';
    if (ada) {
      update_('Absensi', ada, changes);
    } else {
      insert_('Absensi', Object.assign({
        id_absen: newId_('a'), id_batch: batch.id_batch, id_hari: hari.id_hari, id_user: u.id_user, sesi: sesi,
      }, changes));
    }
    log_(user, 'absen_status', u.nama + ' hari ' + hari.hari_ke + ' sesi ' + sesi + ': ' + lama + ' → ' + status +
      (data.keterangan ? ' (' + data.keterangan + ')' : ''));
    return true;
  } finally {
    lock.releaseLock();
  }
}

function absenFoto(data, user) {
  const rec = table_('Absensi').rows.find(a => a.id_absen === data.id_absen);
  if (!rec) throw new Error('Data absen tidak ditemukan.');
  batchForPic_(user, rec.id_batch);
  return {
    foto: fileDataUrl_(rec.foto_id), ttd: fileDataUrl_(rec.ttd_id),
    waktu: rec.waktu_server, lat: rec.lat, lng: rec.lng, jarak_meter: rec.jarak_meter, akurasi_meter: rec.akurasi_meter,
  };
}
