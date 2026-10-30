/** Login, sesi, dan akun. */

const MAX_TOKEN_PIC = 3; // PIC boleh login di beberapa perangkat (mis. laptop + HP)

function hash_(s) {
  const bytes = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, 'lms-asi|' + s, Utilities.Charset.UTF_8);
  return Utilities.base64Encode(bytes);
}

function newToken_() {
  return (Utilities.getUuid() + Utilities.getUuid()).replace(/-/g, '');
}

function isPic_(user) {
  return user.role === 'pic' || user.role === 'admin';
}

function userFromToken_(token) {
  const t = String(token || '');
  const u = t && table_('Pengguna').rows.find(r => r.token && r.token.split(' ').indexOf(t) >= 0);
  if (!u || u.aktif === 'tidak') throw new Error('Sesi login habis, silakan login lagi.');
  return u;
}

function publicUser_(u) {
  return {
    id_user: u.id_user,
    id_batch: u.id_batch,
    no_absen: u.no_absen,
    nama: u.nama,
    role: u.role,
    kode_login: u.kode_login,
    setuju_privasi: !!u.setuju_privasi,
    punya_ttd: !!u.ttd_id,
  };
}

function batchAktif() {
  return table_('Batch').rows
    .filter(b => b.status === 'aktif')
    .map(b => ({ id_batch: b.id_batch, nama_pelatihan: b.nama_pelatihan, kode_batch: b.kode_batch }));
}

function loginPeserta(data) {
  const kode = String(data.kode || '').trim().toLowerCase();
  const deviceId = String(data.device_id || '');
  if (!kode) throw new Error('Kode wajib diisi.');
  if (!deviceId) throw new Error('Perangkat tidak dikenali. Muat ulang halaman.');
  const batch = getBatch_(data.id_batch);
  if (batch.status !== 'aktif') throw new Error('Batch ini sudah tidak aktif.');

  const u = table_('Pengguna').rows.find(r =>
    r.role === 'peserta' && r.id_batch === batch.id_batch && r.kode_login.toLowerCase() === kode);
  if (!u || u.aktif === 'tidak') throw new Error('Kode tidak ditemukan di batch ini. Periksa kembali kode Anda.');
  if (u.device_id && u.device_id !== deviceId) {
    throw new Error('Akun ini sudah terhubung ke HP lain. Jika Anda ganti HP, minta PIC menekan "Reset HP".');
  }
  const token = newToken_();
  update_('Pengguna', u, { device_id: deviceId, token: token });
  log_(u, 'login', 'peserta');
  return { token: token, user: publicUser_(u) };
}

function loginPic(data) {
  const kode = String(data.kode || '').trim().toLowerCase();
  const pin = String(data.pin || '');
  const u = table_('Pengguna').rows.find(r =>
    (r.role === 'pic' || r.role === 'admin') && r.kode_login.toLowerCase() === kode);
  if (!u || u.aktif === 'tidak' || u.pin_hash !== hash_(pin)) throw new Error('Kode atau PIN salah.');
  const token = newToken_();
  const tokens = (u.token ? u.token.split(' ') : []).concat(token).slice(-MAX_TOKEN_PIC);
  update_('Pengguna', u, { token: tokens.join(' ') });
  log_(u, 'login', u.role);
  return { token: token, user: publicUser_(u) };
}

function me(data, user) {
  const out = { user: publicUser_(user) };
  if (user.role === 'peserta') {
    const b = getBatch_(user.id_batch);
    out.batch = { id_batch: b.id_batch, nama_pelatihan: b.nama_pelatihan, kode_batch: b.kode_batch };
  }
  return out;
}

function logout(data, user) {
  const tokens = user.token.split(' ').filter(t => t && t !== data.token);
  update_('Pengguna', user, { token: tokens.join(' ') });
  return true;
}

function setuju(data, user) {
  if (!user.setuju_privasi) update_('Pengguna', user, { setuju_privasi: now_().stamp });
  return publicUser_(user);
}

function gantiPin(data, user) {
  if (user.pin_hash !== hash_(String(data.pin_lama || ''))) throw new Error('PIN lama salah.');
  const baru = String(data.pin_baru || '');
  if (!/^\d{4,8}$/.test(baru)) throw new Error('PIN baru harus 4–8 angka.');
  update_('Pengguna', user, { pin_hash: hash_(baru) });
  log_(user, 'ganti_pin', '');
  return true;
}

// ---------------------------------------------------------------- Akun PIC (Admin)

function picList() {
  return table_('Pengguna').rows
    .filter(u => u.role === 'pic' || u.role === 'admin')
    .map(u => ({ id_user: u.id_user, nama: u.nama, kode_login: u.kode_login, role: u.role, no_hp: u.no_hp, aktif: u.aktif }));
}

function picTambah(data, user) {
  const nama = String(data.nama || '').trim();
  const kode = String(data.kode || '').trim();
  const pin = String(data.pin || '');
  if (!nama) throw new Error('Nama PIC wajib diisi.');
  if (!/^[A-Za-z0-9._-]{3,30}$/.test(kode)) throw new Error('Kode PIC 3–30 karakter (huruf, angka, titik, strip).');
  if (!/^\d{4,8}$/.test(pin)) throw new Error('PIN harus 4–8 angka.');
  const users = table_('Pengguna').rows;
  if (users.some(u => (u.role === 'pic' || u.role === 'admin') && u.kode_login.toLowerCase() === kode.toLowerCase())) {
    throw new Error('Kode PIC sudah dipakai.');
  }
  const u = insert_('Pengguna', {
    id_user: newId_('u'), nama: nama, role: 'pic', kode_login: kode, pin_hash: hash_(pin),
    no_hp: data.no_hp || '', aktif: 'ya', dibuat_pada: now_().stamp,
  });
  log_(user, 'pic_tambah', nama + ' (' + kode + ')');
  return publicUser_(u);
}

function picResetPin(data, user) {
  const u = table_('Pengguna').rows.find(r => r.id_user === data.id_user && (r.role === 'pic' || r.role === 'admin'));
  if (!u) throw new Error('PIC tidak ditemukan.');
  const pin = String(data.pin || '');
  if (!/^\d{4,8}$/.test(pin)) throw new Error('PIN harus 4–8 angka.');
  update_('Pengguna', u, { pin_hash: hash_(pin), token: '' });
  log_(user, 'pic_reset_pin', u.nama);
  return true;
}
