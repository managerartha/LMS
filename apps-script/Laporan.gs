/**
 * Laporan kegiatan PDF: disusun sebagai HTML lalu dikonversi ke PDF oleh Google.
 * Konverter HTML→PDF Google hanya mendukung CSS dasar, jadi tata letak memakai tabel (tanpa flex/grid).
 */

const LAPORAN_KOLOM_ABSEN = 4; // foto absen per baris pada lampiran

const LAPORAN_CSS = [
  'body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #1d2433; }',
  'h1 { font-size: 20pt; color: #1b1e4f; margin: 0 0 6px; }',
  'h2 { font-size: 14pt; color: #1b1e4f; border-bottom: 2px solid #22d89b; padding-bottom: 4px; margin: 18px 0 8px; }',
  'h3 { font-size: 11pt; color: #1b1e4f; margin: 14px 0 6px; }',
  'table { border-collapse: collapse; width: 100%; }',
  'table.data th { background: #e8edff; color: #1b1e4f; font-size: 9pt; padding: 5px; border: 1px solid #9fb0c8; text-align: left; }',
  'table.data td { border: 1px solid #c9d0db; padding: 4px 5px; font-size: 9pt; vertical-align: top; }',
  'table.info td { padding: 4px 6px; font-size: 11pt; vertical-align: top; }',
  'table.grid td { border: 1px solid #c9d0db; padding: 4px; text-align: center; vertical-align: top; font-size: 8pt; }',
  '.muted { color: #667085; }',
  '.small { font-size: 8pt; }',
  '.center { text-align: center; }',
  '.pb { page-break-before: always; }',
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
 * Membuat laporan PDF dan menyimpannya di folder Laporan batch. Mengembalikan isi PDF (base64) untuk diunduh.
 * jenis:
 *  - 'resmi'   : format resmi Artha Safety (cover, penyelenggaraan, jadwal, materi & pemateri, absensi, dokumentasi)
 *  - 'absensi' : laporan absensi per peserta saja; opsional rentang nomor absen (dari, sampai)
 *  - 'harian'  : laporan internal satu hari (butuh id_hari)
 */
function laporanBuat(data, user) {
  const batch = batchForPic_(user, data.id_batch);
  const jenis = data.jenis === 'harian' ? 'harian' : data.jenis === 'absensi' ? 'absensi' : 'resmi';
  let hariList = hariBatch_(batch.id_batch);
  if (!hariList.length) throw new Error('Batch ini belum memiliki hari pelatihan.');
  let nama, html;
  if (jenis === 'harian') {
    const hari = getHari_(data.id_hari);
    if (hari.id_batch !== batch.id_batch) throw new Error('Hari tidak termasuk batch ini.');
    hariList = [hari];
    nama = 'Laporan Harian ' + batch.kode_batch + ' Hari-' + hari.hari_ke + ' (' + hari.tanggal + ').pdf';
    html = laporanHtml_(batch, hariList, jenis);
  } else {
    const dari = Number(data.dari) || 0, sampai = Number(data.sampai) || 0;
    const rentang = jenis === 'absensi' && (dari || sampai) ? ' (No ' + (dari || 1) + '-' + (sampai || 'akhir') + ')' : '';
    nama = (jenis === 'resmi' ? 'Laporan Resmi ' : 'Laporan Absensi ') + batch.kode_batch + ' - ' + batch.nama_pelatihan + rentang + '.pdf';
    html = laporanResmiHtml_(batch, hariList, jenis, dari, sampai);
  }
  nama = nama.replace(/[\\/:*?"<>|]/g, '-');

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
    '<img src="' + include('aset-logo') + '" width="300" alt="Artha Safety Indonesia">',
    '<div class="muted" style="margin-top:4px">Learning Management System</div>',
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

// ================================================================ Laporan resmi (format Artha Safety)

/** Data perusahaan untuk kop, cover, dan halaman penyelenggaraan. Disimpan di Script Properties 'ORG'. */
const ORG_DEFAULT = {
  nama: 'PT Artha Safety Indonesia',
  nomor_skp: 'No. 5/000666/AS.01.02.4103.6.15/V/2026',
  masa_skp: '20 Mei 2028',
  alamat: 'Ruko De Mercy Square No R8. Jl. Kapten Haryadi Komp. Merapi Regency, Sardonoharjo, Ngaglik, Sleman Yogyakarta',
  telepon: '0812-6363-6376',
  telepon_kantor: '(0274) 5063025',
  website: 'www.arthasafetyindonesia.co.id',
  email: 'info@arthasafetyindonesia.co.id',
  penandatangan: 'Saut Reynald Ricardo Simbolon',
  ttd_id: '',
};

/** Data laporan per batch, disimpan sebagai JSON di kolom Batch.info_laporan. */
const INFO_DEFAULT = {
  nomor_permohonan: '',
  judul_cover: 'AHLI K3 UMUM',
  sub_cover: 'Sertifikasi Kementrian Ketenagakerjaan Republik Indonesia',
  bidang_pembinaan: 'Calon Ahli K3 Umum',
  bidang_jasa: 'Keahlian K3 Umum',
  jenis_personil: 'Ahli K3',
  klasifikasi: 'Ahli K3 Umum',
  kelas: 'Non Kelas',
  tempat_kelas: 'PT Artha Safety Indonesia',
  tempat_observasi: '',
  metode: 'Offline Training',
  jumlah_pendaftar: '',
  jumlah_lulus: '',
  surat_permohonan: true,
  bap: true,
  daftar_hadir_pemateri: true,
};

function org_() {
  let o = {};
  try { o = JSON.parse(PropertiesService.getScriptProperties().getProperty('ORG') || '{}'); } catch (err) { o = {}; }
  return Object.assign({}, ORG_DEFAULT, o);
}

function infoBatch_(batch) {
  let o = {};
  try { o = JSON.parse(batch.info_laporan || '{}'); } catch (err) { o = {}; }
  return Object.assign({}, INFO_DEFAULT, o);
}

function infoLaporanGet(data, user) {
  const b = batchForPic_(user, data.id_batch);
  const org = org_();
  return {
    info: infoBatch_(b), org: org, org_ttd: gambar_(org.ttd_id, false),
    jumlah_peserta: pesertaBatch_(b.id_batch, false).length, bisa_ubah_org: user.role === 'admin',
  };
}

function infoLaporanSimpan(data, user) {
  const b = batchForPic_(user, data.id_batch);
  const masuk = data.info || {};
  const info = {};
  Object.keys(INFO_DEFAULT).forEach(k => {
    if (masuk[k] === undefined) return;
    info[k] = typeof INFO_DEFAULT[k] === 'boolean' ? !!masuk[k] : String(masuk[k]).trim().slice(0, 300);
  });
  ['jumlah_pendaftar', 'jumlah_lulus'].forEach(k => {
    if (info[k] && !/^\d{1,4}$/.test(info[k])) throw new Error('Jumlah pendaftar/lulus harus berupa angka.');
  });
  update_('Batch', b, { info_laporan: JSON.stringify(Object.assign(infoBatch_(b), info)) });
  log_(user, 'info_laporan', b.kode_batch);
  return infoBatch_(b);
}

function orgSimpan(data, user) {
  const masuk = data.org || {};
  const o = org_();
  Object.keys(ORG_DEFAULT).forEach(k => {
    if (k !== 'ttd_id' && masuk[k] !== undefined) o[k] = String(masuk[k]).trim().slice(0, 300);
  });
  PropertiesService.getScriptProperties().setProperty('ORG', JSON.stringify(o));
  log_(user, 'org_ubah', '');
  return o;
}

/** Gambar tanda tangan + cap penandatangan (PNG/JPG) untuk halaman penyelenggaraan. */
function orgTtdSimpan(data, user) {
  const o = org_();
  const id = saveDataUrl_(subfolder_(rootFolder_(), 'Tanda Tangan PIC'), data.gambar, 'TTD_CAP_PENYELENGGARA.png');
  trashFile_(o.ttd_id);
  o.ttd_id = id;
  PropertiesService.getScriptProperties().setProperty('ORG', JSON.stringify(o));
  log_(user, 'org_ttd', '');
  return true;
}

function aset_() {
  if (!aset_.cache) aset_.cache = JSON.parse(include('aset-laporan'));
  return aset_.cache;
}

/** '2024-04-24' -> '24 April 2024' */
function tglId_(s) {
  const p = s.split('-').map(Number);
  return p[2] + ' ' + BULAN_ID[p[1] - 1] + ' ' + p[0];
}

/** Rentang tanggal seperti di format: '08–10 November 2023' atau '22 Mei – 20 Juni 2024'. */
function rentangTgl_(a, b) {
  const x = a.split('-').map(Number), y = b.split('-').map(Number);
  const dd = n => ('0' + n).slice(-2);
  if (a === b) return tglId_(a);
  if (x[0] === y[0] && x[1] === y[1]) return dd(x[2]) + '–' + dd(y[2]) + ' ' + BULAN_ID[x[1] - 1] + ' ' + x[0];
  if (x[0] === y[0]) return x[2] + ' ' + BULAN_ID[x[1] - 1] + ' – ' + y[2] + ' ' + BULAN_ID[y[1] - 1] + ' ' + x[0];
  return tglId_(a) + ' – ' + tglId_(b);
}

/** 20 -> 'dua puluh' */
function terbilang_(n) {
  const s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
  n = Math.floor(Number(n) || 0);
  if (n < 12) return n === 0 ? 'nol' : s[n];
  if (n < 20) return terbilang_(n - 10) + ' belas';
  if (n < 100) return terbilang_(Math.floor(n / 10)) + ' puluh' + (n % 10 ? ' ' + terbilang_(n % 10) : '');
  if (n < 200) return 'seratus' + (n % 100 ? ' ' + terbilang_(n % 100) : '');
  if (n < 1000) return terbilang_(Math.floor(n / 100)) + ' ratus' + (n % 100 ? ' ' + terbilang_(n % 100) : '');
  if (n < 2000) return 'seribu' + (n % 1000 ? ' ' + terbilang_(n % 1000) : '');
  return terbilang_(Math.floor(n / 1000)) + ' ribu' + (n % 1000 ? ' ' + terbilang_(n % 1000) : '');
}

function orang_(v) {
  return v === '' || v === null || v === undefined ? '-' : v + ' (' + terbilang_(v) + ') orang';
}

const RESMI_CSS = [
  'body { font-family: "Times New Roman", Times, serif; font-size: 11pt; color: #000; }',
  'table { border-collapse: collapse; }',
  '.pb { page-break-before: always; }',
  '.judul { text-align: center; font-weight: bold; font-size: 12pt; margin: 16px 0 18px; }',
  '.bag { font-weight: bold; margin: 14px 0 6px; }',
  'table.isian td { padding: 3px 6px; vertical-align: top; font-size: 11pt; }',
  'table.garis { width: 100%; }',
  'table.garis th { border: 1px solid #000; padding: 4px; font-size: 10.5pt; background: #c6d4ef; }',
  'table.garis td { border: 1px solid #000; padding: 4px 5px; font-size: 10.5pt; vertical-align: top; }',
  'table.abs { width: 100%; }',
  'table.abs td { border: 1px solid #555; padding: 4px 5px; font-size: 9.5pt; vertical-align: middle; }',
  'table.abs td.foto { text-align: center; width: 150px; padding: 2px; }',
  'table.dok td { border: 1px solid #999; padding: 4px; text-align: center; vertical-align: top; font-size: 9pt; width: 50%; }',
  '.kop-web { font-family: Arial, sans-serif; font-size: 8pt; color: #333; text-align: right; vertical-align: middle; }',
  '.cover { font-family: Arial, Helvetica, sans-serif; text-align: center; color: #000; }',
].join('\n');

function kop_(org) {
  return '<table style="width:100%"><tr><td><img src="' + aset_().kop + '" width="230"></td>' +
    '<td class="kop-web">' + h_(org.website) + '</td></tr></table>' +
    '<div style="border-bottom:1px solid #1b1e4f;margin:4px 0 8px"></div>';
}

/** Halaman resmi: kop + isi, dimulai di halaman baru (kecuali halaman pertama). */
function halaman_(org, isi, pertama) {
  return (pertama ? '' : '<div class="pb"></div>') + kop_(org) + isi;
}

function laporanResmiHtml_(batch, hariList, jenis, dari, sampai) {
  const org = org_();
  const info = infoBatch_(batch);
  const now = now_();
  let peserta = pesertaBatch_(batch.id_batch, false);
  if (dari || sampai) peserta = peserta.filter(u => Number(u.no_absen) >= (dari || 1) && Number(u.no_absen) <= (sampai || 99999));
  const out = ['<html><head><meta charset="utf-8"><style>' + RESMI_CSS + '</style></head><body>'];
  if (jenis === 'resmi') {
    out.push(coverResmi_(batch, info, org));
    out.push(halaman_(org, penyelenggaraan_(batch, hariList, info, org, now)));
    out.push(halaman_(org, jadwalKegiatan_(hariList)));
    out.push(halaman_(org, daftarMateri_(hariList)));
  }
  out.push(absensiPeserta_(batch, hariList, info, peserta, now, jenis !== 'resmi'));
  if (jenis === 'resmi') out.push(halaman_(org, dokumentasiResmi_(hariList)));
  out.push('</body></html>');
  return out.join('\n');
}

function coverResmi_(batch, info, org) {
  return '<div class="cover"><br><br>' +
    '<div style="font-size:18pt;font-weight:bold">PEMBINAAN</div><br>' +
    '<div style="font-size:30pt;font-weight:bold">' + h_(info.judul_cover) + '</div><br>' +
    '<div style="font-size:11pt;font-weight:bold">' + h_(info.sub_cover) + '</div><br>' +
    '<div style="font-size:11pt;font-weight:bold">Tanggal ' + h_(rentangTgl_(batch.tgl_mulai, batch.tgl_selesai)) + '</div>' +
    '<br><br><br><img src="' + aset_().cover + '" width="300"><br><br><br><br>' +
    '<div style="font-size:10pt;font-weight:bold">Diselenggarakan oleh:</div><br>' +
    '<div style="font-size:16pt;font-weight:bold">' + h_(org.nama.toUpperCase()) + '</div><br><br>' +
    '<div style="font-size:11pt;font-weight:bold">Alamat Kantor :</div><br>' +
    '<div style="font-size:9.5pt;font-weight:bold">' + h_(org.alamat) + (org.telepon_kantor ? ' ' + h_(org.telepon_kantor) : '') + '</div>' +
    '</div>';
}

function isian_(rows) {
  return '<table class="isian" style="margin-left:28px">' + rows.map(r =>
    '<tr><td style="width:230px">' + r[0] + '</td><td>' + (r[1] === null ? '' : ': ' + h_(r[1])) + '</td></tr>').join('') + '</table>';
}

function penyelenggaraan_(batch, hariList, info, org, now) {
  const idHari = hariList.map(h => h.id_hari);
  const adaRundown = table_('Rundown').rows.some(r => idHari.indexOf(r.id_hari) >= 0);
  const adaAbsen = table_('Absensi').rows.some(a => a.id_batch === batch.id_batch && a.status === 'hadir');
  const adaDok = table_('Dokumentasi').rows.some(d => d.id_batch === batch.id_batch);
  const syarat = [
    ['Surat Permohonan', info.surat_permohonan], ['BAP', info.bap], ['Jadwal Kegiatan', adaRundown],
    ['Daftar Hadir Pemateri', info.daftar_hadir_pemateri], ['Daftar Hadir Peserta', adaAbsen], ['Foto Kegiatan', adaDok],
  ];
  const jumlahPeserta = pesertaBatch_(batch.id_batch, false).length;
  const pendaftar = info.jumlah_pendaftar === '' ? jumlahPeserta : info.jumlah_pendaftar;
  return [
    '<div class="judul">LAPORAN PENYELENGGARAAN KEGIATAN</div>',
    '<div class="bag">A.&nbsp;&nbsp;PENYELENGGARA</div>',
    isian_([['a.&nbsp;&nbsp;Nama Penyelenggara', org.nama], ['b.&nbsp;&nbsp;Nomor SKP', org.nomor_skp], ['c.&nbsp;&nbsp;Masa Berlaku SKP', org.masa_skp]]),
    '<div class="bag">B.&nbsp;&nbsp;PELAKSANAAN KEGIATAN</div>',
    isian_([
      ['a.&nbsp;&nbsp;Nomor Permohonan Temank3', info.nomor_permohonan || '-'],
      ['b.&nbsp;&nbsp;Tempat Pelaksanaan', null],
      ['&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Kelas', info.tempat_kelas || '-'],
      ['&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Observasi/PKL', info.tempat_observasi || '-'],
      ['c.&nbsp;&nbsp;Metode', info.metode || '-'],
      ['d.&nbsp;&nbsp;Waktu Pelaksanaan', rentangTgl_(batch.tgl_mulai, batch.tgl_selesai)],
      ['e.&nbsp;&nbsp;Bidang Pembinaan', info.bidang_pembinaan || '-'],
    ]),
    '<div class="bag">C.&nbsp;&nbsp;PERSYARATAN ADMINISTRASI</div>',
    '<table class="garis" style="margin-left:28px;width:90%"><tr><th style="width:35px">No</th><th>Persyaratan Administrasi</th><th style="width:50px">Ada</th><th style="width:50px">Tidak</th></tr>' +
      syarat.map((x, i) => '<tr><td style="text-align:center">' + (i + 1) + '</td><td>' + x[0] + '</td><td style="text-align:center">' +
        (x[1] ? '&#8730;' : '') + '</td><td style="text-align:center">' + (x[1] ? '' : '&#8730;') + '</td></tr>').join('') + '</table>',
    '<div class="bag">D.&nbsp;&nbsp;KEPESERTAAN</div>',
    isian_([['a.&nbsp;&nbsp;Jumlah Pendaftar', orang_(pendaftar)], ['b.&nbsp;&nbsp;Jumlah Peserta', orang_(jumlahPeserta)], ['c.&nbsp;&nbsp;Jumlah Peserta Lulus', orang_(info.jumlah_lulus)]]),
    '<div class="bag">E.&nbsp;&nbsp;PENUTUP</div>',
    '<div style="margin-left:28px">Demikian laporan ini kami sampaikan dengan data yang sebenar-benarnya.</div><br>',
    '<table style="width:100%"><tr><td style="width:55%"></td><td style="text-align:center">Hormat kami,<br>' + h_(org.nama) + '<br>' +
      (org.ttd_id ? img_(gambar_(org.ttd_id, false), 'height="95"') : '<br><br><br><br><br>') +
      '<br><b>' + h_(org.penandatangan) + '</b></td></tr></table>',
  ].join('\n');
}

function jadwalKegiatan_(hariList) {
  const out = ['<div class="judul">JADWAL KEGIATAN</div>'];
  hariList.forEach(h => {
    const rd = rundownHari_(h.id_hari);
    if (!rd.length) return;
    out.push('<div class="bag">Hari ke-' + h_(h.hari_ke) + ' — ' + h_(tglPanjang_(h.tanggal)) + '</div>');
    out.push('<table class="garis"><tr><th style="width:95px">Waktu</th><th>Kegiatan</th><th>Pemateri</th><th>Keterangan</th></tr>' +
      rd.map(x => '<tr><td>' + h_(x.jam_mulai) + '–' + h_(x.jam_selesai) + '</td><td>' + h_(x.kegiatan) + '</td><td>' + h_(x.pemateri) +
        '</td><td>' + h_(x.keterangan) + '</td></tr>').join('') + '</table>');
  });
  if (out.length === 1) out.push('<p>Rundown belum diisi.</p>');
  return out.join('\n');
}

/** Materi dan pemateri unik dari rundown semua hari (kegiatan yang punya nama pemateri). */
function daftarMateri_(hariList) {
  const list = [];
  hariList.forEach(h => rundownHari_(h.id_hari).forEach(x => {
    if (!x.pemateri) return;
    if (!list.some(m => m.kegiatan === x.kegiatan && m.pemateri === x.pemateri)) list.push(x);
  }));
  return '<div class="judul">DAFTAR MATERI DAN PEMATERI</div>' + (list.length
    ? '<table class="garis"><tr><th style="width:35px">No</th><th>Materi</th><th>Nama Trainer</th><th>Instansi</th></tr>' +
      list.map((x, i) => '<tr><td style="text-align:center">' + (i + 1) + '</td><td>' + h_(x.kegiatan) + '</td><td>' + h_(x.pemateri) +
        '</td><td>' + h_(x.instansi || '') + '</td></tr>').join('') + '</table>'
    : '<p>Belum ada kegiatan rundown yang mencantumkan nama pemateri.</p>');
}

/** Laporan absensi per peserta seperti format resmi: identitas di atas, lalu setiap sesi dengan foto & tanda tangan. */
function absensiPeserta_(batch, hariList, info, peserta, now, halamanPertama) {
  const out = [];
  const head = '<div style="font-size:13pt;line-height:1.35;margin:0 0 14px 20px">LAPORAN ABSENSI<br>' +
    'NO PERMOHONAN : ' + h_(info.nomor_permohonan || '-') + '<br>BIDANG JASA : ' + h_(info.bidang_jasa) + '<br>' +
    'JENIS PERSONIL : ' + h_(info.jenis_personil) + '<br>KLASIFIKASI : ' + h_(info.klasifikasi) + '<br>KELAS : ' + h_(info.kelas) + '</div>';
  if (!peserta.length) return (halamanPertama ? '' : '<div class="pb"></div>') + head + '<p>Tidak ada peserta.</p>';
  peserta.forEach((u, i) => {
    out.push((i === 0 && halamanPertama ? '' : '<div class="pb"></div>') + head);
    const rows = ['<tr><td style="width:32%"><b>Nama</b></td><td><b>' + h_(u.nama) + '</b></td><td class="foto"></td></tr>'];
    hariList.forEach(h => SESI.forEach(s => {
      const state = stateSesi_(batch, h, s, now);
      if (state === 'belum_dibuka') return;
      const rec = absenOf_(h.id_hari, u.id_user, s);
      const status = statusSesi_(rec, state);
      const hadir = status === 'hadir' && rec && rec.foto_id;
      let jam, lokasi = '-', foto = '';
      if (hadir) {
        jam = tglId_(rec.waktu_server.slice(0, 10)) + ', Jam : ' + rec.waktu_server.slice(11, 19);
        lokasi = alamatAbsen_(rec);
        foto = img_(gambar_(rec.foto_id, true), 'width="140"') + '<br>' + img_(gambar_(rec.ttd_id, false), 'height="42"');
      } else {
        jam = 'Tidak hadir (' + (STATUS_TEKS[status] || status) + (rec && rec.keterangan ? ' – ' + rec.keterangan : '') + ')';
      }
      rows.push('<tr><td><b>Sesi Absen</b></td><td><b>' + (s === 1 ? 'Pagi' : 'Siang') + '</b></td><td class="foto" rowspan="4">' + foto + '</td></tr>');
      rows.push('<tr><td><b>Tanggal Pelatihan</b></td><td><b>' + h_(tglId_(h.tanggal)) + '</b></td></tr>');
      rows.push('<tr><td>Tanggal &amp; Jam Absensi</td><td>' + h_(jam) + '</td></tr>');
      rows.push('<tr><td>Lokasi Absen</td><td>' + h_(lokasi) + '</td></tr>');
    }));
    out.push('<table class="abs">' + rows.join('') + '</table>');
  });
  return out.join('\n');
}

/** Alamat lokasi absen; untuk data lama tanpa alamat, dicari dari koordinat lalu disimpan. */
function alamatAbsen_(rec) {
  if (rec.alamat) return rec.alamat;
  const a = alamat_(rec.lat, rec.lng);
  if (a) {
    try { update_('Absensi', rec, { alamat: a }); } catch (err) { /* kolom belum ada: cukup tampilkan */ }
    return a;
  }
  return rec.lat ? rec.lat + ', ' + rec.lng : '-';
}

function dokumentasiResmi_(hariList) {
  const out = ['<div class="judul">DOKUMENTASI KEGIATAN</div>'];
  hariList.forEach(h => {
    const dok = dokHari_(h.id_hari).filter(d => d.masuk_laporan === 'ya');
    if (!dok.length) return;
    const rd = rundownHari_(h.id_hari);
    out.push('<div class="bag">Hari ke-' + h_(h.hari_ke) + ' — ' + h_(tglPanjang_(h.tanggal)) + '</div>');
    const cells = dok.map(d => {
      const keg = d.id_rundown && rd.find(x => x.id_rundown === d.id_rundown);
      const src = d.file_kecil_id ? gambar_(d.file_kecil_id, false) : gambar_(d.file_id, true);
      return '<td>' + img_(src, 'width="300"') + (keg ? '<br><b>' + h_(keg.kegiatan) + '</b>' : '') +
        (d.keterangan ? '<br>' + h_(d.keterangan) : '') + '</td>';
    });
    const rows = [];
    for (let i = 0; i < cells.length; i += 2) rows.push('<tr>' + cells[i] + (cells[i + 1] || '<td style="border:0"></td>') + '</tr>');
    out.push('<table class="dok" style="width:100%">' + rows.join('') + '</table>');
  });
  if (out.length === 1) out.push('<p>Belum ada foto kegiatan yang ditandai masuk laporan.</p>');
  return out.join('\n');
}
