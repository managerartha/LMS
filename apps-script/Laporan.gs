/**
 * Laporan kegiatan PDF: disusun sebagai HTML lalu dikonversi ke PDF oleh Google.
 * Konverter HTML→PDF Google hanya mendukung CSS dasar, jadi tata letak memakai tabel (tanpa flex/grid).
 */

const LAPORAN_KOLOM_ABSEN = 4; // foto absen per baris pada lampiran

const LAPORAN_CSS = [
  'body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #1d2433; }',
  'h1 { font-size: 20pt; color: #0b3a6e; margin: 0 0 6px; }',
  'h2 { font-size: 14pt; color: #0b3a6e; border-bottom: 2px solid #f28c28; padding-bottom: 4px; margin: 18px 0 8px; }',
  'h3 { font-size: 11pt; color: #0b3a6e; margin: 14px 0 6px; }',
  'table { border-collapse: collapse; width: 100%; }',
  'table.data th { background: #e8eef7; color: #0b3a6e; font-size: 9pt; padding: 5px; border: 1px solid #9fb0c8; text-align: left; }',
  'table.data td { border: 1px solid #c9d0db; padding: 4px 5px; font-size: 9pt; vertical-align: top; }',
  'table.info td { padding: 4px 6px; font-size: 11pt; vertical-align: top; }',
  'table.grid td { border: 1px solid #c9d0db; padding: 4px; text-align: center; vertical-align: top; font-size: 8pt; }',
  '.muted { color: #667085; }',
  '.small { font-size: 8pt; }',
  '.center { text-align: center; }',
  '.pb { page-break-before: always; }',
  '.logo { color: #f28c28; border: 3px solid #f28c28; font-weight: bold; font-size: 20pt; padding: 6px 10px; }',
  '.hadir { color: #12805c; font-weight: bold; } .izin, .sakit { color: #1f5fbf; font-weight: bold; } .alpa { color: #b42318; font-weight: bold; }',
].join('\n');

const STATUS_TEKS = { hadir: 'Hadir', izin: 'Izin', sakit: 'Sakit', alpa: 'Alpa', belum: 'Belum absen', '': '-' };
const BULAN_ID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const HARI_ID = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

function h_(s) {
  return String(s === undefined || s === null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** 'yyyy-mm-dd' -> 'Selasa, 3 November 2026' */
function tglPanjang_(s) {
  const p = s.split('-').map(Number);
  const d = new Date(Date.UTC(p[0], p[1] - 1, p[2]));
  return HARI_ID[d.getUTCDay()] + ', ' + p[2] + ' ' + BULAN_ID[p[1] - 1] + ' ' + p[0];
}

/** Gambar dari Drive sebagai data URI. kecil=true memakai thumbnail Drive bila tersedia. */
function gambar_(fileId, kecil) {
  if (!fileId) return '';
  try {
    const f = DriveApp.getFileById(fileId);
    let blob = kecil ? f.getThumbnail() : null;
    if (!blob) blob = f.getBlob();
    return 'data:' + (blob.getContentType() || 'image/jpeg') + ';base64,' + Utilities.base64Encode(blob.getBytes());
  } catch (err) {
    console.warn('Gambar tidak bisa dimuat', fileId, err);
    return '';
  }
}

function img_(src, attrs) {
  return src ? '<img src="' + src + '" ' + attrs + '>' : '<span class="muted small">(gambar tidak tersedia)</span>';
}

// ---------------------------------------------------------------- Tanda tangan PIC

function ttdSaya(data, user) {
  return { ttd: gambar_(user.ttd_id, false) };
}

function ttdSayaSimpan(data, user) {
  const folder = subfolder_(rootFolder_(), 'Tanda Tangan PIC');
  const id = saveDataUrl_(folder, data.ttd, 'TTD_' + user.kode_login + '.png');
  trashFile_(user.ttd_id);
  update_('Pengguna', user, { ttd_id: id });
  log_(user, 'ttd_pic', '');
  return true;
}

// ---------------------------------------------------------------- Laporan

function laporanView_(r) {
  let nama = r.nama_file;
  if (!nama) {
    try { nama = DriveApp.getFileById(r.file_id).getName(); } catch (err) { nama = 'Laporan.pdf'; }
  }
  return {
    id_laporan: r.id_laporan, jenis: r.jenis, id_hari: r.id_hari, nama: nama,
    dibuat_oleh: r.dibuat_oleh, dibuat_pada: r.dibuat_pada,
    drive: 'https://drive.google.com/file/d/' + r.file_id + '/view',
  };
}

function laporanList(data, user) {
  const b = batchForPic_(user, data.id_batch);
  return table_('Laporan').rows
    .filter(r => r.id_batch === b.id_batch)
    .sort((x, y) => y.dibuat_pada.localeCompare(x.dibuat_pada))
    .map(laporanView_);
}

function laporanUnduh(data, user) {
  const r = table_('Laporan').rows.find(x => x.id_laporan === data.id_laporan);
  if (!r) throw new Error('Laporan tidak ditemukan.');
  batchForPic_(user, r.id_batch);
  const v = laporanView_(r);
  return { nama: v.nama, data: Utilities.base64Encode(DriveApp.getFileById(r.file_id).getBlob().getBytes()) };
}

/**
 * Membuat laporan PDF dan menyimpannya di folder Laporan batch.
 * jenis 'harian' (butuh id_hari) atau 'lengkap' (semua hari). Mengembalikan isi PDF (base64) untuk diunduh.
 */
function laporanBuat(data, user) {
  const batch = batchForPic_(user, data.id_batch);
  const jenis = data.jenis === 'lengkap' ? 'lengkap' : 'harian';
  let hariList = hariBatch_(batch.id_batch);
  let nama = 'Laporan Lengkap ' + batch.kode_batch + ' - ' + batch.nama_pelatihan + '.pdf';
  if (jenis === 'harian') {
    const hari = getHari_(data.id_hari);
    if (hari.id_batch !== batch.id_batch) throw new Error('Hari tidak termasuk batch ini.');
    hariList = [hari];
    nama = 'Laporan Harian ' + batch.kode_batch + ' Hari-' + hari.hari_ke + ' (' + hari.tanggal + ').pdf';
  }
  if (!hariList.length) throw new Error('Batch ini belum memiliki hari pelatihan.');
  nama = nama.replace(/[\\/:*?"<>|]/g, '-');

  const html = laporanHtml_(batch, hariList, jenis);
  const pdf = Utilities.newBlob(html, 'text/html', 'laporan.html').getAs('application/pdf').setName(nama);
  const file = subfolder_(DriveApp.getFolderById(batch.folder_id), 'Laporan').createFile(pdf);

  const rec = insert_('Laporan', {
    id_laporan: newId_('l'), id_batch: batch.id_batch, jenis: jenis, id_hari: jenis === 'harian' ? hariList[0].id_hari : '',
    file_id: file.getId(), dibuat_oleh: user.nama, dibuat_pada: now_().stamp, nama_file: nama,
  });
  log_(user, 'laporan_' + jenis, nama);
  return { laporan: laporanView_(rec), nama: nama, data: Utilities.base64Encode(pdf.getBytes()) };
}

/** Menyusun seluruh isi laporan sebagai satu dokumen HTML. */
function laporanHtml_(batch, hariList, jenis) {
  const now = now_();
  const peserta = pesertaBatch_(batch.id_batch, false);
  const pics = table_('Pengguna').rows.filter(u => batch.pic_ids.split(',').indexOf(u.id_user) >= 0);

  // Rekap status semua peserta, dihitung sekali dan dipakai di ringkasan & per hari.
  const rekap = hariList.map(h => {
    const per = peserta.map(u => SESI.map(s => {
      const rec = absenOf_(h.id_hari, u.id_user, s);
      return { rec: rec, status: statusSesi_(rec, stateSesi_(batch, h, s, now)) };
    }));
    const hitung = SESI.map((s, i) => {
      const c = { hadir: 0, izin: 0, sakit: 0, alpa: 0, belum: 0 };
      per.forEach(p => { if (c[p[i].status] !== undefined) c[p[i].status]++; });
      return c;
    });
    return { hari: h, per: per, hitung: hitung };
  });

  const out = [];
  out.push('<html><head><meta charset="utf-8"><style>' + LAPORAN_CSS + '</style></head><body>');
  out.push(sampul_(batch, hariList, jenis, pics, peserta.length));
  out.push(ringkasan_(rekap, peserta.length));
  rekap.forEach(r => out.push(bagianHari_(batch, r, peserta)));
  out.push(pengesahan_(batch, pics, now));
  out.push('</body></html>');
  return out.join('\n');
}

function sampul_(batch, hariList, jenis, pics, jumlahPeserta) {
  const lokasi = [];
  hariList.forEach(h => {
    const n = lokasiHari_(batch, h).nama;
    if (lokasi.indexOf(n) < 0) lokasi.push(n);
  });
  const periode = hariList.length === 1 ? tglPanjang_(hariList[0].tanggal)
    : tglPanjang_(hariList[0].tanggal) + ' s.d. ' + tglPanjang_(hariList[hariList.length - 1].tanggal);
  return [
    '<table><tr><td style="width:80px;padding-right:12px"><span class="logo">ASI</span></td>',
    '<td><b style="font-size:14pt">ARTHA SAFETY INDONESIA</b><br><span class="muted">Learning Management System</span></td></tr></table>',
    '<br><br><br><br>',
    '<h1>' + (jenis === 'harian' ? 'LAPORAN HARIAN KEGIATAN PELATIHAN' : 'LAPORAN KEGIATAN PELATIHAN') + '</h1>',
    '<div style="font-size:16pt;margin-bottom:24px">' + h_(batch.nama_pelatihan) + '</div>',
    '<table class="info">',
    '<tr><td style="width:150px">Batch</td><td>: ' + h_(batch.kode_batch) + '</td></tr>',
    jenis === 'harian' ? '<tr><td>Hari ke</td><td>: ' + h_(hariList[0].hari_ke) + '</td></tr>' : '',
    '<tr><td>Tanggal</td><td>: ' + h_(periode) + '</td></tr>',
    '<tr><td>Lokasi</td><td>: ' + h_(lokasi.join('; ')) + '</td></tr>',
    '<tr><td>Jumlah peserta</td><td>: ' + jumlahPeserta + ' orang</td></tr>',
    '<tr><td>PIC</td><td>: ' + h_(pics.map(p => p.nama).join(', ') || '-') + '</td></tr>',
    '</table>',
  ].join('\n');
}

function ringkasan_(rekap, total) {
  const rows = [];
  rekap.forEach(r => SESI.forEach((s, i) => {
    const c = r.hitung[i];
    const belumDibuka = r.per.every(p => p[i].status === '');
    const pct = total && !belumDibuka ? Math.round(c.hadir / total * 100) + '%' : '-';
    rows.push('<tr><td>' + (i === 0 ? 'Hari ke-' + h_(r.hari.hari_ke) + '<br><span class="small muted">' + h_(tglPanjang_(r.hari.tanggal)) + '</span>' : '') + '</td>' +
      '<td>Sesi ' + s + '</td><td class="center">' + c.hadir + '</td><td class="center">' + c.izin + '</td><td class="center">' + c.sakit +
      '</td><td class="center">' + c.alpa + '</td><td class="center">' + (c.belum || '-') + '</td><td class="center"><b>' + pct + '</b></td></tr>');
  }));
  return [
    '<div class="pb"></div><h2>Ringkasan Kehadiran</h2>',
    '<p>Jumlah peserta: <b>' + total + '</b> orang. Persentase dihitung dari jumlah peserta yang hadir dibanding seluruh peserta.</p>',
    '<table class="data"><tr><th>Hari</th><th>Sesi</th><th>Hadir</th><th>Izin</th><th>Sakit</th><th>Alpa</th><th>Belum</th><th>% Hadir</th></tr>',
    rows.join(''), '</table>',
  ].join('\n');
}

function bagianHari_(batch, r, peserta) {
  const h = r.hari;
  const lok = lokasiHari_(batch, h);
  const out = [];
  out.push('<div class="pb"></div><h2>Hari ke-' + h_(h.hari_ke) + ' — ' + h_(tglPanjang_(h.tanggal)) + '</h2>');
  out.push('<p class="muted">Lokasi: ' + h_(lok.nama) + '</p>');

  // a. Rundown
  const rd = rundownHari_(h.id_hari);
  out.push('<h3>A. Rundown Kegiatan</h3>');
  out.push(rd.length
    ? '<table class="data"><tr><th style="width:85px">Waktu</th><th>Kegiatan</th><th>Pemateri</th><th>Keterangan</th></tr>' +
      rd.map(x => '<tr><td>' + h_(x.jam_mulai) + '–' + h_(x.jam_selesai) + '</td><td>' + h_(x.kegiatan) + '</td><td>' + h_(x.pemateri) +
        '</td><td>' + h_(x.keterangan) + '</td></tr>').join('') + '</table>'
    : '<p class="muted">Rundown belum diisi.</p>');

  // b. Rekap absensi
  out.push('<h3>B. Daftar Hadir</h3>');
  out.push('<table class="data"><tr><th style="width:30px">No</th><th>Nama</th><th>Instansi</th>' +
    SESI.map(s => '<th>Sesi ' + s + ' (' + h_(jamSesi_(batch, s).mulai) + '–' + h_(jamSesi_(batch, s).selesai) + ')</th>').join('') + '</tr>' +
    peserta.map((u, i) => '<tr><td class="center">' + h_(u.no_absen) + '</td><td>' + h_(u.nama) + '</td><td>' + h_(u.instansi) + '</td>' +
      r.per[i].map(x => '<td><span class="' + x.status + '">' + h_(STATUS_TEKS[x.status] || x.status) + '</span>' +
        (x.rec && x.rec.waktu_server && x.status === 'hadir' ? ' ' + h_(x.rec.waktu_server.slice(11, 16)) : '') +
        (x.rec && x.rec.keterangan ? '<br><span class="small muted">' + h_(x.rec.keterangan) + '</span>' : '') + '</td>').join('') +
      '</tr>').join('') + '</table>');

  // c. Lampiran foto timestamp & tanda tangan per sesi
  SESI.forEach((s, i) => {
    const hadir = peserta.map((u, j) => ({ u: u, rec: r.per[j][i].rec, status: r.per[j][i].status }))
      .filter(x => x.status === 'hadir' && x.rec && x.rec.foto_id);
    const judul = '<h3>C.' + s + ' Lampiran Absensi Sesi ' + s + ' — Hari ke-' + h_(h.hari_ke) + '</h3>';
    if (!hadir.length) { out.push(judul + '<p class="muted">Tidak ada peserta yang absen hadir pada sesi ini.</p>'); return; }
    out.push('<div class="pb"></div>' + judul);
    const cells = hadir.map(x =>
      '<td style="width:' + Math.floor(100 / LAPORAN_KOLOM_ABSEN) + '%">' +
      img_(gambar_(x.rec.foto_id, true), 'width="150"') +
      '<br><b>' + h_(x.u.nama) + '</b><br>' + h_(x.u.kode_login) + ' · ' + h_(x.rec.waktu_server.slice(11, 19)) + ' WIB' +
      '<br><span class="muted">± ' + h_(x.rec.jarak_meter) + ' m dari lokasi</span><br>' +
      img_(gambar_(x.rec.ttd_id, false), 'height="45"') + '</td>');
    out.push(gridTable_(cells, LAPORAN_KOLOM_ABSEN));
  });

  // d. Dokumentasi
  const dok = dokHari_(h.id_hari).filter(d => d.masuk_laporan === 'ya');
  out.push('<div class="pb"></div><h3>D. Dokumentasi Kegiatan</h3>');
  if (dok.length) {
    const cells = dok.map(d => {
      const keg = d.id_rundown && rd.find(x => x.id_rundown === d.id_rundown);
      const src = d.file_kecil_id ? gambar_(d.file_kecil_id, false) : gambar_(d.file_id, true);
      return '<td style="width:50%">' + img_(src, 'width="320"') +
        (keg ? '<br><b>' + h_(keg.jam_mulai) + ' · ' + h_(keg.kegiatan) + '</b>' : '') +
        (d.keterangan ? '<br>' + h_(d.keterangan) : '') + '</td>';
    });
    out.push(gridTable_(cells, 2));
  } else {
    out.push('<p class="muted">Tidak ada dokumentasi.</p>');
  }

  // e. Materi
  const materi = materiHari_(h.id_hari);
  out.push('<h3>E. Materi</h3>');
  out.push(materi.length
    ? '<table class="data"><tr><th style="width:30px">No</th><th>Judul</th><th>Kategori</th><th>Jenis</th></tr>' +
      materi.map((m, i) => '<tr><td class="center">' + (i + 1) + '</td><td>' + h_(m.judul) + '</td><td>' +
        (m.kategori === 'latihan' ? 'Lembar latihan' : 'Materi') + '</td><td>' + h_(m.tipe_file.toUpperCase()) + '</td></tr>').join('') + '</table>'
    : '<p class="muted">Tidak ada materi.</p>');
  return out.join('\n');
}

function gridTable_(cells, cols) {
  const rows = [];
  for (let i = 0; i < cells.length; i += cols) {
    const row = cells.slice(i, i + cols);
    while (row.length < cols) row.push('<td style="border:0"></td>');
    rows.push('<tr>' + row.join('') + '</tr>');
  }
  return '<table class="grid">' + rows.join('') + '</table>';
}

function pengesahan_(batch, pics, now) {
  const cols = pics.length ? pics : [{ nama: '', ttd_id: '' }];
  return [
    '<div class="pb"></div><h2>Pengesahan</h2>',
    '<p>Laporan ini disusun otomatis oleh LMS Artha Safety Indonesia berdasarkan data absensi, rundown, materi, dan dokumentasi kegiatan ',
    h_(batch.nama_pelatihan) + ' batch ' + h_(batch.kode_batch) + '.</p>',
    '<p>Dibuat pada: ' + h_(tglPanjang_(now.date)) + ', pukul ' + h_(now.time) + ' WIB</p><br>',
    '<table><tr>' + cols.map((p, i) =>
      '<td class="center" style="width:' + Math.floor(100 / cols.length) + '%;vertical-align:top">PIC ' + (cols.length > 1 ? i + 1 : '') +
      '<br><br>' + (p.ttd_id ? img_(gambar_(p.ttd_id, false), 'height="70"') : '<br><br><br><br>') +
      '<br><b><u>' + h_(p.nama || '(..............................)') + '</u></b></td>').join('') + '</tr></table>',
  ].join('\n');
}
