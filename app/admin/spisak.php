<?php
/**
 * Administrator – jedan spisak za utovar (jedan kamion): podaci o utovaru i mesta istovara
 * (Mesto 8, 9, 5 … – broj upisuje vlasnik, kupac nije obavezan). Svako mesto ima svoju robu: artikle (kuca se
 * "humovit", pa se dodirne pakovanje 5 / 10 / 25 / 50 l; više artikala se čuva odjednom), zbirne palete
 * (sve odjednom, npr. 1/2 rizle + 1/2 oblutka) i "ostalo". Ceo spisak sa papira: admin/spisak_papir.php.
 * Zbir paleta i težine, upozorenja kad nema dovoljno slobodne robe i promena statusa.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/podesavanja.php';
require __DIR__ . '/../inc/spiskovi.php';
require __DIR__ . '/../inc/spisak_izmene.php';

$admin = zahtevaj_ulogu('admin');
$id = je_post() ? post_int('id') : get_int('id');
$sp = spisak_ucitaj($id);
if ($sp === null) {
    stranica_greske(404, 'Spisak ne postoji', 'Traženi spisak za utovar ne postoji.', 'admin/utovar.php', 'Svi spiskovi');
}
$stranica = 'admin/spisak.php?id=' . $id;

/**
 * Dodaje robu za mesto (jedan ili više artikala odjednom) u jednoj izmeni nacrta.
 * $pid > 0 = u zbirnu paletu. Vraća [upisano?, id mesta].
 */
function dodaj_robu(int $id, int $mesto_id, int $pid, array $redovi): array
{
    $mid = 0;
    $ok = u_nacrtu($id, static function () use ($id, $mesto_id, $pid, $redovi, &$mid) {
        $broj = 1;
        $paleta = '';
        if ($pid > 0) {
            $pl = db_one('SELECT * FROM spisak_palete WHERE id = ? AND spisak_id = ?', [$pid, $id]);
            if ($pl === null) {
                return ['greska' => 'Zbirna paleta ne postoji.'];
            }
            if ($mesto_id === 0) {
                $mesto_id = (int)$pl['mesto_id'];      // stara forma: mesto je mesto palete
            } elseif ((int)$pl['mesto_id'] !== $mesto_id) {
                return ['greska' => 'Zbirna paleta „' . $pl['naziv'] . '“ nije na tom mestu.'];
            }
            $broj = (int)$pl['broj'];
            $paleta = ' (' . $pl['naziv'] . ')';
        }
        $m = mesto_na_spisku($id, $mesto_id);
        if (isset($m['greska'])) {
            return $m;
        }
        $mid = (int)$m['id'];
        $gde = mesto_naslov((int)$m['broj'], $m['kupac']);
        $rez = spisak_upisi_robu($id, $mid, $pid, $broj, $redovi);
        if (isset($rez['greska'])) {
            return $rez;
        }
        [$opisi, $zamenjeno] = $rez;
        if (count($redovi) === 1 && $zamenjeno === 1) {
            $r = reset($redovi);
            return 'stavka ' . sku_naziv($r['sku']) . $paleta . ' je već bila na spisku za ' . $gde . ' – zamenjena novom količinom ('
                . broj((int)$r['komadi'] * $broj) . ' kom)';
        }
        return 'dodato za ' . $gde . $paleta . ': ' . implode(', ', $opisi);
    });
    return [$ok, $mid];
}

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    $ukloni = post_int('ukloni');
    $ukloni_paletu = post_int('ukloni_paletu');
    $ukloni_mesto = post_int('ukloni_mesto');
    $pomeri = post_str('pomeri_mesto', 24);
    $posle = $stranica;

    if ($ukloni_mesto > 0) {
        u_nacrtu($id, static function () use ($id, $ukloni_mesto) {
            $m = mesto_na_spisku($id, $ukloni_mesto);
            if (isset($m['greska'])) {
                return $m;
            }
            $n = (int)db_val('SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id = ? AND mesto_id = ?', [$id, $ukloni_mesto]);
            db_run('DELETE FROM spisak_stavke WHERE spisak_id = ? AND mesto_id = ?', [$id, $ukloni_mesto]);
            db_run('DELETE FROM spisak_palete WHERE spisak_id = ? AND mesto_id = ?', [$id, $ukloni_mesto]);
            db_run('DELETE FROM spisak_mesta WHERE id = ?', [$ukloni_mesto]);
            return 'uklonjeno ' . mesto_naslov((int)$m['broj'], $m['kupac']) . ($n > 0 ? ' sa svom robom (' . stavki_tekst($n) . ')' : '');
        });
    } elseif (preg_match('/^(\d{1,10}):(gore|dole)$/', $pomeri, $pm)) {
        $mid = (int)$pm[1];
        $smer = $pm[2];
        $posle = $stranica . '#mesto-' . $mid;
        u_nacrtu($id, static function () use ($id, $mid, $smer) {
            $mesta = spisak_mesta($id);
            $ids = array_map(static fn(array $m): int => (int)$m['id'], $mesta);
            $i = array_search($mid, $ids, true);
            if ($i === false) {
                return ['greska' => 'To mesto više nije na spisku.'];
            }
            $j = $smer === 'gore' ? $i - 1 : $i + 1;
            if ($j < 0 || $j >= count($ids)) {
                return '';
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $k => $m_id) {
                db_run('UPDATE spisak_mesta SET redosled = ? WHERE id = ?', [($k + 1) * 10, $m_id]);
            }
            return 'pomereno ' . mesto_naslov((int)$mesta[$i]['broj'], $mesta[$i]['kupac']) . ' ' . $smer;
        });
    } elseif ($ukloni_paletu > 0) {
        u_nacrtu($id, static function () use ($id, $ukloni_paletu, $stranica, &$posle) {
            $pl = db_one('SELECT * FROM spisak_palete WHERE id = ? AND spisak_id = ?', [$ukloni_paletu, $id]);
            if ($pl === null) {
                return ['greska' => 'Zbirna paleta ne postoji.'];
            }
            $posle = $stranica . '#mesto-' . (int)$pl['mesto_id'];
            $gde = mesto_opis((int)$pl['mesto_id']);
            db_run('DELETE FROM spisak_stavke WHERE spisak_id = ? AND paleta_id = ?', [$id, $ukloni_paletu]);
            db_run('DELETE FROM spisak_palete WHERE id = ?', [$ukloni_paletu]);
            return 'uklonjena zbirna paleta „' . $pl['naziv'] . '“' . ($gde !== '' ? ' (' . $gde . ')' : '') . ' sa svim artiklima';
        });
    } elseif ($ukloni > 0) {
        u_nacrtu($id, static function () use ($id, $ukloni, $stranica, &$posle) {
            $st = db_one('SELECT * FROM spisak_stavke WHERE id = ? AND spisak_id = ?', [$ukloni, $id]);
            if ($st === null) {
                return ['greska' => 'Stavka ne postoji.'];
            }
            $posle = $stranica . '#mesto-' . (int)$st['mesto_id'];
            $naziv = $st['sku_id'] !== null ? sku_naziv((array)sku_podaci((int)$st['sku_id'])) : (string)$st['naziv'];
            $gde = array_filter([
                mesto_opis((int)$st['mesto_id']),
                (int)$st['paleta_id'] > 0 ? (string)db_val('SELECT naziv FROM spisak_palete WHERE id = ?', [(int)$st['paleta_id']]) : '',
            ]);
            db_run('DELETE FROM spisak_stavke WHERE id = ?', [$ukloni]);
            return 'uklonjena stavka ' . $naziv . ($gde ? ' (' . implode(', ', $gde) . ')' : '');
        });
    } elseif ($akcija === 'dodaj_mesto') {
        [$broj, $greska] = mesto_broj_iz(post_str('novo_broj', 4));
        $kupac = post_str('novo_kupac', 120);
        $adresa = post_str('novo_adresa', 255);
        $posle = $stranica . '#novo-mesto';
        if ($greska !== null) {
            flash_dodaj('greska', $greska);
        } else {
            $novo = 0;
            u_nacrtu($id, static function () use ($id, $broj, $kupac, $adresa, &$novo) {
                if (db_val('SELECT id FROM spisak_mesta WHERE spisak_id = ? AND broj = ?', [$id, $broj]) !== null) {
                    return ['greska' => 'Mesto ' . $broj . ' već postoji na ovom spisku – upišite drugi broj.'];
                }
                $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM spisak_mesta WHERE spisak_id = ?', [$id]) + 10;
                db_run(
                    'INSERT INTO spisak_mesta (spisak_id, broj, kupac_id, adresa, redosled) VALUES (?, ?, ?, ?, ?)',
                    [$id, $broj, $kupac !== '' ? kupac_id_za($kupac) : null, $adresa !== '' ? $adresa : null, $red]
                );
                $novo = db_id();
                return 'dodato ' . mesto_naslov($broj, $kupac) . ' – sada dodajte robu za to mesto';
            });
            if ($novo > 0) {
                $posle = $stranica . '&m=' . $novo . '#dodaj-' . $novo;
            }
        }
    } elseif ($akcija === 'nova_paleta') {
        // zbirna paleta: sve odjednom (artikal + koliko na jednoj paleti, npr. rizla 1/2 + oblutak 1/2) ili prazna (stara forma)
        $mesto_id = max(0, post_int('mesto_id'));
        $naziv = post_str('p_naziv', 60);
        $broj_t = post_str('p_broj', 4);
        $broj_t = $broj_t === '' ? '1' : $broj_t;
        $sa_robom = is_array($_POST['z_artikal'] ?? null);
        $polja = [
            'z_artikal' => post_niz('z_artikal', 120, 20),
            'z_id'      => post_niz('z_id', 10, 20),
            'z_kol'     => post_niz('z_kol', 12, 20),
            'p_naziv'   => $naziv,
            'p_broj'    => $broj_t,
        ];
        $posle = $stranica . ($mesto_id > 0 ? '&m=' . $mesto_id . ($sa_robom ? '&z=1#zbirna-' : '#dodaj-') . $mesto_id : '');
        $ulaz = [];
        foreach ($polja['z_artikal'] as $i => $t) {
            $ulaz[] = [ctype_digit($polja['z_id'][$i] ?? '') ? (int)$polja['z_id'][$i] : 0, $t, $polja['z_kol'][$i] ?? ''];
        }
        [$redovi, $greska] = $sa_robom ? zbirna_redovi($ulaz) : [[], null];
        if (!ctype_digit($broj_t) || (int)$broj_t < 1 || (int)$broj_t > 999) {
            $greska = 'Broj ovakvih paleta mora biti od 1 do 999.';
        }
        $nova = 0;
        $mid = 0;
        if ($greska !== null) {
            flash_dodaj('greska', $greska);
        } else {
            u_nacrtu($id, static function () use ($id, $mesto_id, $naziv, $broj_t, $redovi, &$nova, &$mid) {
                $m = mesto_na_spisku($id, $mesto_id);
                if (isset($m['greska'])) {
                    return $m;
                }
                $mid = (int)$m['id'];
                $broj = (int)$broj_t;
                $z = spisak_nova_zbirna($id, $mid, $naziv, $broj, $redovi);
                if (isset($z['greska'])) {
                    return $z;
                }
                $nova = (int)$z['pid'];
                $gde = mesto_naslov((int)$m['broj'], $m['kupac']);
                $koliko = $broj > 1 ? ' (' . palete_tekst($broj) . ')' : '';
                if (!$z['opis']) {
                    return 'napravljena zbirna paleta „' . $z['naziv'] . '“' . $koliko . ' za ' . $gde . ' – dodajte u nju artikle (Gde ide: ' . $z['naziv'] . ')';
                }
                return 'napravljena zbirna paleta „' . $z['naziv'] . '“' . $koliko . ' za ' . $gde . ': ' . implode(' + ', $z['opis']) . ' kom' . ($broj > 1 ? ' na svakoj' : '');
            });
        }
        if ($nova > 0) {
            $posle = $stranica . '&m=' . $mid . ($redovi ? '#paleta-' . $nova : '&u=' . $nova . '#dodaj-' . $mid);
        } elseif ($sa_robom) {
            zapamti_formu($id, 'zbirna', $mesto_id, $polja);
        }
    } elseif ($akcija === 'zaglavlje') {
        [$p, $greska] = spisak_zaglavlje_iz_posta();
        if ($greska !== null) {
            flash_dodaj('greska', $greska);
        } else {
            u_nacrtu($id, static function (array $staro) use ($id, $p) {
                $novo = [$p['datum'], $p['vozilo'] ?: null, $p['vozac'] ?: null, $p['napomena'] ?: null];
                if ($novo === [(string)$staro['datum'], $staro['vozilo'], $staro['vozac'], $staro['napomena']]) {
                    return '';
                }
                db_run('UPDATE spiskovi SET datum = ?, vozilo = ?, vozac = ?, napomena = ? WHERE id = ?', [...$novo, $id]);
                return 'izmenjeni podaci (utovar ' . datum_srp($p['datum']) . ')';
            });
        }
    } elseif ($akcija === 'dodaj' || $akcija === 'dodaj_vise') {
        // "dodaj_vise": više artikala odjednom (r_tekst[], r_id[], r_pal[] …); "dodaj": jedan artikal (starija forma)
        $mesto_id = max(0, post_int('mesto_id'));
        $pid = max(0, post_int('paleta_id'));
        $posle = $stranica . ($mesto_id > 0 ? '&m=' . $mesto_id . ($pid > 0 ? '&u=' . $pid : '') . '#dodaj-' . $mesto_id : '');
        if ($akcija === 'dodaj_vise') {
            $polja = roba_polja();
            [$redovi, $greska] = roba_iz_posta($pid > 0);
        } else {
            $polja = ['r_tekst' => [post_str('artikal', 120)], 'r_id' => [post_int('sku_id') ?: ''], 'r_pal' => [post_str('pal', 8)], 'r_pak' => [post_str('pak', 8)], 'r_kom' => [post_str('kom', 8)]];
            $r = roba_red(post_int('sku_id'), post_str('artikal', 120), post_str('pal', 8), post_str('pak', 8), post_str('kom', 8), $pid > 0);
            [$redovi, $greska] = isset($r['greska']) ? [[], ucfirst($r['greska'])] : [[(int)$r['sku']['sku_id'] => $r], null];
        }
        $ok = false;
        if ($greska !== null) {
            flash_dodaj('greska', $greska);
        } else {
            [$ok, $mid] = dodaj_robu($id, $mesto_id, $pid, $redovi);
            if ($ok) {
                $posle = $stranica . '&m=' . $mid . ($pid > 0 ? '&u=' . $pid . '#paleta-' . $pid : '#mesto-' . $mid);
            }
        }
        if (!$ok) {
            zapamti_formu($id, 'dodaj', $mesto_id, $polja + ['paleta_id' => $pid]);
        }
    } elseif ($akcija === 'ostalo') {
        $mesto_id = max(0, post_int('mesto_id'));
        $naziv = post_str('o_naziv', 120);
        $kol = post_str('o_kolicina', 8);
        $jedinica = post_str('o_jedinica', 20) ?: 'kom';
        [$kg, $greska] = kg_iz_posta('o_kg');
        $posle = $stranica . ($mesto_id > 0 ? '&m=' . $mesto_id . '#dodaj-' . $mesto_id : '');
        $ok = false;
        if (mb_strlen($naziv) < 2) {
            flash_dodaj('greska', 'Upišite naziv robe.');
        } elseif (!ctype_digit($kol) || (int)$kol <= 0) {
            flash_dodaj('greska', 'Količina mora biti ceo broj veći od 0.');
        } elseif ($greska !== null) {
            flash_dodaj('greska', $greska);
        } else {
            $ok = u_nacrtu($id, static function () use ($id, $mesto_id, $naziv, $kol, $jedinica, $kg) {
                $m = mesto_na_spisku($id, $mesto_id);
                if (isset($m['greska'])) {
                    return $m;
                }
                $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM spisak_stavke WHERE spisak_id = ?', [$id]) + 10;
                db_run(
                    'INSERT INTO spisak_stavke (spisak_id, mesto_id, sku_id, naziv, jedinica, tezina_kg, kolicina, redosled) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)',
                    [$id, (int)$m['id'], $naziv, $jedinica, $kg, (int)$kol, $red]
                );
                return 'dodato za ' . mesto_naslov((int)$m['broj'], $m['kupac']) . ' (ostalo): ' . $naziv . ' – ' . broj((int)$kol) . ' ' . $jedinica;
            });
        }
        if (!$ok) {
            zapamti_formu($id, 'ostalo', $mesto_id, ['o_naziv' => $naziv, 'o_kolicina' => $kol, 'o_jedinica' => $jedinica, 'o_kg' => post_str('o_kg', 10)]);
        }
    } elseif ($akcija === 'stavke') {
        u_nacrtu($id, static function () use ($id) {
            $niz = static fn(string $k): array => is_array($_POST[$k] ?? null) ? $_POST[$k] : [];
            [$p_pal, $p_pak, $p_kom, $p_okol] = [$niz('pal'), $niz('pak'), $niz('kom'), $niz('o_kol')];
            [$p_pnaziv, $p_pbroj] = [$niz('pl_naziv'), $niz('pl_broj')];
            [$p_mbroj, $p_mkupac, $p_madresa] = [$niz('m_broj'), $niz('m_kupac'), $niz('m_adresa')];
            $izmene = 0;

            // mesta: broj, kupac i adresa (dva mesta ne mogu imati isti broj)
            $brojevi = [];
            foreach (spisak_mesta($id) as $m) {
                $mid = (int)$m['id'];
                [$broj, $greska] = is_string($p_mbroj[$mid] ?? null) ? mesto_broj_iz($p_mbroj[$mid]) : [(int)$m['broj'], null];
                if ($greska !== null) {
                    return ['greska' => mesto_naslov((int)$m['broj'], $m['kupac']) . ': ' . lcfirst($greska)];
                }
                if (isset($brojevi[$broj])) {
                    return ['greska' => 'Dva mesta ne mogu imati isti broj (' . $broj . ').'];
                }
                $brojevi[$broj] = true;
                $kupac = is_string($p_mkupac[$mid] ?? null) ? ulaz_str($p_mkupac, (string)$mid, 120) : (string)$m['kupac'];
                $adresa = is_string($p_madresa[$mid] ?? null) ? ulaz_str($p_madresa, (string)$mid, 255) : (string)$m['adresa'];
                $kupac_id = $kupac === '' ? null : kupac_id_za($kupac);
                if ($broj !== (int)$m['broj'] || $kupac_id !== ($m['kupac_id'] === null ? null : (int)$m['kupac_id']) || $adresa !== (string)$m['adresa']) {
                    db_run('UPDATE spisak_mesta SET broj = ?, kupac_id = ?, adresa = ? WHERE id = ?', [$broj, $kupac_id, $adresa !== '' ? $adresa : null, $mid]);
                    $izmene++;
                }
            }

            // zbirne palete: naziv i broj ovakvih paleta
            $palete = [];
            foreach (spisak_palete($id) as $pl) {
                $pid = (int)$pl['id'];
                $naziv = is_string($p_pnaziv[$pid] ?? null) ? ulaz_str($p_pnaziv, (string)$pid, 60) : (string)$pl['naziv'];
                $broj_t = is_string($p_pbroj[$pid] ?? null) ? trim($p_pbroj[$pid]) : (string)$pl['broj'];
                if ($naziv === '') {
                    return ['greska' => 'Zbirna paleta mora imati naziv.'];
                }
                if (!ctype_digit($broj_t) || (int)$broj_t < 1 || (int)$broj_t > 999) {
                    return ['greska' => $pl['naziv'] . ': broj ovakvih paleta mora biti od 1 do 999.'];
                }
                $palete[$pid] = ['stari' => (int)$pl['broj'], 'novi' => (int)$broj_t];
                if ($naziv !== $pl['naziv'] || (int)$broj_t !== (int)$pl['broj']) {
                    db_run('UPDATE spisak_palete SET naziv = ?, broj = ? WHERE id = ?', [$naziv, (int)$broj_t, $pid]);
                    $izmene++;
                }
            }

            foreach (spisak_stavke($id) as $st) {
                $sid = (int)$st['stavka_id'];
                $pid = (int)$st['paleta_id'];
                if ($st['sku_id'] === null) {
                    $kol = is_string($p_okol[$sid] ?? null) ? trim($p_okol[$sid]) : null;
                    if ($kol === null) {
                        continue;
                    }
                    if (!ctype_digit($kol) || (int)$kol <= 0) {
                        return ['greska' => stavka_naziv($st) . ': količina mora biti ceo broj veći od 0 (za brisanje koristite Ukloni).'];
                    }
                    if ((int)$kol !== (int)$st['kolicina']) {
                        db_run('UPDATE spisak_stavke SET kolicina = ? WHERE id = ?', [(int)$kol, $sid]);
                        $izmene++;
                    }
                    continue;
                }
                if ($pid > 0) {
                    // u zbirnoj paleti se upisuje koliko ide na JEDNU paletu; ukupno = to × broj paleta
                    $broj = $palete[$pid]['novi'] ?? (int)$st['paleta_broj'];
                    $stari = $palete[$pid]['stari'] ?? (int)$st['paleta_broj'];
                    if (isset($p_pak[$sid]) || isset($p_kom[$sid])) {
                        $r = popis_vrednost('', '', $p_pak[$sid] ?? '', $p_kom[$sid] ?? '', $st);
                        if (isset($r['greska'])) {
                            return ['greska' => $r['greska']];
                        }
                        if (!$r['komadi']) {
                            return ['greska' => stavka_puni_naziv($st) . ': količina ne može biti 0 (za brisanje koristite Ukloni).'];
                        }
                        $na_paleti = (int)$r['komadi'];
                        $pak = (int)($p_pak[$sid] ?? 0);
                        $kom = (int)($p_kom[$sid] ?? 0);
                    } else {
                        $na_paleti = intdiv((int)$st['kolicina'], max(1, $stari));
                        $pak = (int)$st['u_paketi'];
                        $kom = (int)$st['u_komadi'];
                    }
                    $nova = $na_paleti * $broj;
                    if ($nova > 99999999) {
                        return ['greska' => 'Količina je prevelika.'];
                    }
                    if ($nova !== (int)$st['kolicina'] || $pak !== (int)$st['u_paketi'] || $kom !== (int)$st['u_komadi']) {
                        db_run('UPDATE spisak_stavke SET kolicina = ?, palete = 0, paketi = ?, komadi = ? WHERE id = ?', [$nova, $pak, $kom, $sid]);
                        $izmene++;
                    }
                    continue;
                }
                if (!isset($p_pal[$sid]) && !isset($p_pak[$sid]) && !isset($p_kom[$sid])) {
                    continue;
                }
                $r = popis_vrednost('', $p_pal[$sid] ?? '', $p_pak[$sid] ?? '', $p_kom[$sid] ?? '', $st);
                if (isset($r['greska'])) {
                    return ['greska' => $r['greska']];
                }
                if (!$r['komadi']) {
                    return ['greska' => stavka_naziv($st) . ': količina ne može biti 0 (za brisanje koristite Ukloni).'];
                }
                $pal = (int)($p_pal[$sid] ?? 0);
                $pak = (int)($p_pak[$sid] ?? 0);
                $kom = (int)($p_kom[$sid] ?? 0);
                if ($r['komadi'] !== (int)$st['kolicina'] || $pal !== (int)$st['u_palete'] || $pak !== (int)$st['u_paketi'] || $kom !== (int)$st['u_komadi']) {
                    db_run('UPDATE spisak_stavke SET kolicina = ?, palete = ?, paketi = ?, komadi = ? WHERE id = ?', [$r['komadi'], $pal, $pak, $kom, $sid]);
                    $izmene++;
                }
            }
            return $izmene > 0 ? 'sačuvane izmene (' . $izmene . ')' : '';
        });
    } elseif ($akcija === 'obrisi_spisak') {
        $rez = spisak_obrisi($id, (int)$admin['id']);
        if ($rez['ok']) {
            flash_dodaj('uspeh', 'Spisak #' . $id . ' je ' . $rez['opis'] . '.');
            preusmeri('admin/utovar.php');
        }
        flash_dodaj('greska', (string)$rez['greska']);
    } elseif ($akcija === 'status') {
        $novi = post_str('novi', 12);
        $ocekivan = post_str('ocekivan', 12);
        $rez = spisak_promeni_status($id, $ocekivan, $novi, (int)$admin['id']);
        if (!$rez['ok']) {
            flash_dodaj('greska', (string)$rez['greska']);
        } else {
            $poruke = [
                'za_utovar' => ['poslat na utovar', 'Spisak je poslat na utovar: radnici ga vide, a količine su rezervisane.'],
                'nacrt'     => ['vraćen u nacrt', 'Spisak je vraćen u nacrt – možete ga menjati. Radnici ga ne vide dok ga ponovo ne pošaljete.'],
                'otkazano'  => $ocekivan === 'utovareno'
                    ? ['otkazan utovar – roba vraćena na stanje', 'Utovar je otkazan i sva roba je vraćena na stanje (povrat).']
                    : ['otkazan', 'Spisak je otkazan. Ništa nije skinuto sa stanja.'],
            ];
            spisak_dnevnik($id, $poruke[$novi][0]);
            flash_dodaj('uspeh', $poruke[$novi][1]);
        }
    }
    preusmeri($posle);
}

$stavke = spisak_stavke($id);
$mesta = spisak_mesta($id);
$po_mestima = spisak_po_mestima($stavke, $mesta);
$palete_mesta = [];
foreach (spisak_palete($id) as $pl) {
    $palete_mesta[(int)$pl['mesto_id']][] = $pl;
}
$aktivno_mesto = get_int('m');
$izabrana_paleta = get_int('u');
$utovaren = $sp['status'] === 'utovareno';
$z = spisak_zbir($stavke, $utovaren);
$nacrt = $sp['status'] === 'nacrt';
$manjak = in_array($sp['status'], ['nacrt', 'za_utovar'], true) ? spisak_manjak($id, $stavke) : [];
$stanja = stanja_svih();
$broj_mesta = count($mesta);
$sku_unos = $nacrt && $mesta ? sku_za_unos(true) : [];   // za izbor artikla kucanjem (sa stanjem)
$forma = $_SESSION['spisak_forma'][$id] ?? null;          // upisano u formu koja nije uspela
unset($_SESSION['spisak_forma'][$id]);
$otvori_zbirnu = get_int('z') === 1;
$kupci = $nacrt ? db_all('SELECT naziv FROM kupci ORDER BY (poslednja_prodaja IS NULL), poslednja_prodaja DESC, naziv LIMIT 300') : [];
$nedavni = array_slice(array_column($kupci, 'naziv'), 0, 6);
$kupci_tekst = spisak_kupci($sp);

ui_start('Spisak #' . $id, ['nav' => 'admin', 'aktivno' => 'utovar', 'js' => ['assets/js/spisak.js', 'assets/js/prodaja.js']]);
?>
<p><a class="btn btn-mali" href="<?= e(url('admin/utovar.php')) ?>"><?= ikona('nazad') ?> Svi spiskovi</a></p>

<section class="kartica spisak-<?= e($sp['status']) ?>">
    <div class="kartica-naslov">
        <h2><?= e(spisak_naslov($sp)) ?></h2>
        <span class="znacka status-<?= e($sp['status']) ?>"><?= e(SPISAK_STATUSI[$sp['status']]) ?></span>
    </div>
    <?php if ($kupci_tekst !== ''): ?><p class="spisak-kupci"><?= e($kupci_tekst) ?></p><?php endif; ?>
    <dl class="spisak-podaci">
        <div><dt>Utovar</dt><dd><?= e(dan_u_nedelji((string)$sp['datum'])) ?>, <?= e(datum_srp((string)$sp['datum'])) ?></dd></div>
        <?php if ($sp['vozilo']): ?><div><dt>Vozilo</dt><dd><?= e($sp['vozilo']) ?></dd></div><?php endif; ?>
        <?php if ($sp['vozac']): ?><div><dt>Vozač</dt><dd><?= e($sp['vozac']) ?></dd></div><?php endif; ?>
        <?php if ($sp['napomena']): ?><div><dt>Napomena</dt><dd><?= e($sp['napomena']) ?></dd></div><?php endif; ?>
        <div><dt>Napravio</dt><dd><?= e($sp['napravio']) ?>, <?= e(datum_vreme_srp((string)$sp['napravljen'])) ?></dd></div>
        <?php if ($sp['utovareno_u']): ?><div><dt>Utovario</dt><dd><?= e($sp['utovario'] ?? '—') ?>, <?= e(datum_vreme_srp((string)$sp['utovareno_u'])) ?></dd></div><?php endif; ?>
        <?php if ($sp['otkazano_u']): ?><div><dt>Otkazao</dt><dd><?= e($sp['otkazao'] ?? '—') ?>, <?= e(datum_vreme_srp((string)$sp['otkazano_u'])) ?></dd></div><?php endif; ?>
    </dl>

    <div class="stat-mreza tri">
        <div class="stat"><span class="broj"><?= $broj_mesta ?></span><span class="opis"><?= $broj_mesta % 10 === 1 && $broj_mesta % 100 !== 11 ? 'mesto' : 'mesta' ?> istovara</span></div>
        <div class="stat"><span class="broj"><?= e(broj(round($z['palete'], 1), round($z['palete'], 1) == floor(round($z['palete'], 1)) ? 0 : 1)) ?></span><span class="opis">paleta<?= $z['zbirnih'] ? ' (zbirnih: ' . (int)$z['zbirnih'] . ')' : '' ?><?= $z['bez_palete'] ? ' (+' . (int)$z['bez_palete'] . ' bez palete)' : '' ?></span></div>
        <div class="stat"><span class="broj"><?= e(kg_tekst($z['kg'])) ?></span><span class="opis">težina<?= $z['bez_tezine'] ? ' (' . (int)$z['bez_tezine'] . ' bez težine)' : '' ?></span></div>
    </div>

    <p class="grupa-dugmica razmak-gore">
        <a class="btn btn-mali" href="<?= e(url('admin/spisak_stampa.php?id=' . $id)) ?>"><?= ikona('preuzmi') ?> Štampa / PDF / Podeli</a>
        <?php if ($nacrt): ?><a class="btn btn-mali" href="<?= e(url('admin/spisak_papir.php?id=' . $id)) ?>"><?= ikona('olovka') ?> Spisak sa papira</a><?php endif; ?>
    </p>

    <?php if ($nacrt): ?>
        <details class="razmak-gore">
            <summary class="btn btn-mali"><?= ikona('olovka') ?> Izmeni datum, vozilo, vozača</summary>
            <form method="post" autocomplete="off" class="razmak-gore" action="<?= e(url('admin/spisak.php')) ?>">
                <?= csrf_polje() ?>
                <input type="hidden" name="akcija" value="zaglavlje">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="red-polja">
                    <label for="datum">Datum utovara</label>
                    <input class="polje" id="datum" name="datum" type="date" required value="<?= e((string)$sp['datum']) ?>">
                </div>
                <div class="red-polja-2 red-polja">
                    <div><label for="vozilo">Vozilo</label><input class="polje" id="vozilo" name="vozilo" type="text" maxlength="60" value="<?= e($sp['vozilo'] ?? '') ?>"></div>
                    <div><label for="vozac">Vozač</label><input class="polje" id="vozac" name="vozac" type="text" maxlength="60" value="<?= e($sp['vozac'] ?? '') ?>"></div>
                </div>
                <div class="red-polja">
                    <label for="napomena">Napomena</label>
                    <input class="polje" id="napomena" name="napomena" type="text" maxlength="255" value="<?= e($sp['napomena'] ?? '') ?>">
                </div>
                <button class="btn btn-primary btn-blok" type="submit">Sačuvaj podatke</button>
            </form>
        </details>
    <?php endif; ?>
</section>

<?php if ($manjak): ?>
    <div class="poruka poruka-upozorenje" role="status">
        <strong>Upozorenje:</strong> za <?= count($manjak) ?> <?= count($manjak) === 1 ? 'stavku' : 'stavke' ?> na spisku ima više nego što je slobodno (stanje minus ono što je već rezervisano na drugim spiskovima). Spisak može da se pošalje, ovo je samo upozorenje.
    </div>
<?php endif; ?>

<?php if ($nacrt): ?>
    <?php /* Jedna forma za sve izmene na mestima (polja su u karticama mesta, vezana atributom form="izmene"). */ ?>
    <form method="post" id="izmene" autocomplete="off" action="<?= e(url('admin/spisak.php')) ?>">
        <?= csrf_polje() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php /* prvo dugme forme: Enter u polju čuva izmene (a ne briše ili pomera) */ ?>
        <button class="skriven-vizuelno" type="submit" name="akcija" value="stavke" tabindex="-1" aria-hidden="true">Sačuvaj izmene</button>
    </form>
<?php endif; ?>

<?php
/** Jedna stavka spiska: kratak red (naziv, količina, težina); u nacrtu dodir otvara izmenu količine i brisanje. */
$prikazi_stavku = static function (array $st) use ($nacrt, $utovaren, $manjak, $stanja): void {
    $sid = (int)$st['stavka_id'];
    $kol = $utovaren && $st['utovareno'] !== null ? (int)$st['utovareno'] : (int)$st['kolicina'];
    $kg = stavka_kg($st, $kol);
    $ostalo = $st['sku_id'] === null;
    $u_paleti = (int)$st['paleta_id'] > 0;
    ob_start(); ?>
        <span class="stavka-prvi">
            <span class="stavka-glavno"><strong class="stavka-naziv"><?= e(stavka_naziv($st)) ?></strong> <span class="stavka-kol"><?= e(stavka_plan_tekst($st)) ?></span></span>
            <span class="stavka-kg"><?= $kg !== null ? '≈ ' . e(kg_tekst($kg)) : '' ?></span>
            <?php if ($nacrt): ?><span class="stavka-olovka"><?= ikona('olovka', 'ikona ikona-mala') ?></span><?php endif; ?>
        </span>
        <?php if ($ostalo || $st['dodao_id'] !== null || (!$ostalo && $st['oznaka'])): ?>
            <span class="stavka-ime">
                <?php if (!$ostalo && $st['oznaka']): ?><span class="znacka znacka-pl"><?= e($st['oznaka']) ?></span><?php endif; ?>
                <?php if ($ostalo): ?><span class="znacka">ostalo – ne vodi se na stanju</span><?php endif; ?>
                <?php if ($st['dodao_id'] !== null): ?><span class="znacka status-za_utovar">dodato pri utovaru · <?= e($st['dodao'] ?? '') ?></span><?php endif; ?>
            </span>
        <?php endif; ?>
        <?php if ($utovaren && $st['utovareno'] !== null && (int)$st['utovareno'] !== (int)$st['kolicina']): ?>
            <span class="stavka-ime tekst-crveno">utovareno <?= e(broj((int)$st['utovareno'])) ?> umesto <?= e(broj((int)$st['kolicina'])) ?></span>
        <?php endif; ?>
        <?php if (isset($manjak[$sid])): $m = $manjak[$sid]; ?>
            <span class="stavka-upoz"><?= ikona('upozorenje', 'ikona ikona-mala') ?> slobodno je samo <?= e(broj($m['slobodno'])) ?> kom (stanje <?= e(broj($stanja[(int)$st['sku_id']] ?? 0)) ?>)<?= $m['trazi'] !== $m['stavka'] ? ', a na spisku ukupno ' . e(broj($m['trazi'])) . ' kom' : '' ?></span>
        <?php endif; ?>
    <?php $red = (string)ob_get_clean(); ?>
    <li class="stavka<?= isset($manjak[$sid]) ? ' stavka-manjak' : '' ?>">
        <?php if (!$nacrt): ?>
            <div class="stavka-red"><?= $red ?></div>
        <?php else: ?>
            <details class="stavka-uredi">
                <summary class="stavka-red"><?= $red ?></summary>
                <div class="stavka-izmena" data-popis data-po="<?= $u_paleti ? 0 : (int)$st['po_paleti'] ?>" data-pp="<?= (int)$st['po_paketu'] ?>" data-broj="<?= $u_paleti ? (int)$st['paleta_broj'] : 1 ?>" data-sada="<?= (int)$st['kolicina'] ?>">
                    <?php if ($ostalo): ?>
                        <label class="mala-oznaka"><?= e($st['ostalo_jedinica'] ?: 'kom') ?><input class="polje" name="o_kol[<?= $sid ?>]" form="izmene" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" value="<?= (int)$st['kolicina'] ?>"></label>
                    <?php else: ?>
                        <?php if (!$u_paleti && (int)$st['po_paleti'] > 0): ?><label class="mala-oznaka">paleta<input class="polje" name="pal[<?= $sid ?>]" form="izmene" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= (int)$st['u_palete'] ?: '' ?>" placeholder="0" data-p="pal"></label><?php endif; ?>
                        <?php if ((int)$st['po_paketu'] > 0): ?><label class="mala-oznaka">paketa<?= $u_paleti ? ' na pal.' : '' ?><input class="polje" name="pak[<?= $sid ?>]" form="izmene" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= (int)$st['u_paketi'] ?: '' ?>" placeholder="0" data-p="pak"></label><?php endif; ?>
                        <label class="mala-oznaka">komada<?= $u_paleti ? ' na pal.' : '' ?><input class="polje" name="kom[<?= $sid ?>]" form="izmene" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" value="<?= (int)$st['u_komadi'] ?: '' ?>" placeholder="0" data-p="kom"></label>
                    <?php endif; ?>
                    <button class="btn btn-mali btn-opasno" type="submit" form="izmene" name="ukloni" value="<?= $sid ?>" data-potvrda="Ukloniti „<?= e(stavka_puni_naziv($st)) ?>“ sa spiska?" aria-label="Ukloni <?= e(stavka_naziv($st)) ?>"><?= ikona('kanta') ?></button>
                </div>
                <?php if (!$ostalo): ?><div class="popis-zbir" data-zbir aria-live="polite"></div><?php endif; ?>
            </details>
        <?php endif; ?>
    </li>
    <?php
};
?>

<?php if ($nacrt && $stavke): ?>
    <p class="pomoc mesta-pomoc">Dodirnite stavku da promenite količinu ili je uklonite, pa <strong>Sačuvaj izmene</strong>.</p>
<?php endif; ?>
<?php if (!$po_mestima): ?>
    <div class="kartica">
        <p class="pomoc<?= $nacrt ? '' : ' bez-margine' ?>">Spisak još nema nijedno mesto istovara<?= $nacrt ? ' – dodajte prvo mesto ispod (Dodaj mesto) ili prepišite ceo spisak sa papira' : '' ?>.</p>
        <?php if ($nacrt): ?><a class="btn btn-braon btn-blok" href="<?= e(url('admin/spisak_papir.php?id=' . $id)) ?>"><?= ikona('olovka') ?> Prepiši spisak sa papira</a><?php endif; ?>
    </div>
<?php endif; ?>

<?php
$redni = 0;
foreach ($po_mestima as $mid => $grupa):
    $m = $grupa['mesto'];
    $mst = $grupa['stavke'];
    $mpalete = $palete_mesta[$mid] ?? [];
    $mz = spisak_zbir($mst, $utovaren);
    $naslov = $m !== null ? mesto_naslov((int)$m['broj'], $m['kupac']) : 'Bez mesta';
    $menja = $nacrt && $m !== null;
    $prvo = $redni === 0;
    $poslednje = $redni === $broj_mesta - 1;
    if ($m !== null) {
        $redni++;
    }
    ?>
    <section class="kartica mesto-kartica" id="mesto-<?= (int)$mid ?>" aria-labelledby="mesto-<?= (int)$mid ?>-naslov">
        <div class="mesto-glava">
            <?php if ($m !== null): ?><span class="mesto-krug" aria-hidden="true"><?= (int)$m['broj'] ?></span><?php endif; ?>
            <div class="mesto-ime">
                <h2 id="mesto-<?= (int)$mid ?>-naslov"><?= $m !== null ? 'Mesto ' . (int)$m['broj'] : 'Bez mesta' ?></h2>
                <?php if ($m !== null && $m['kupac'] !== null): ?><span class="mesto-kupac"><?= e($m['kupac']) ?></span><?php endif; ?>
                <?php if ($m !== null && $m['adresa'] !== null): ?><span class="mesto-adresa"><?= e($m['adresa']) ?></span><?php endif; ?>
            </div>
            <?php if ($menja): ?>
                <div class="mesto-akcije">
                    <?php if (!$prvo): ?><button class="btn btn-mali" type="submit" form="izmene" name="pomeri_mesto" value="<?= (int)$mid ?>:gore" aria-label="Pomeri <?= e($naslov) ?> gore" title="Pomeri gore">↑</button><?php endif; ?>
                    <?php if (!$poslednje): ?><button class="btn btn-mali" type="submit" form="izmene" name="pomeri_mesto" value="<?= (int)$mid ?>:dole" aria-label="Pomeri <?= e($naslov) ?> dole" title="Pomeri dole">↓</button><?php endif; ?>
                    <button class="btn btn-mali btn-opasno" type="submit" form="izmene" name="ukloni_mesto" value="<?= (int)$mid ?>"
                            data-potvrda="Ukloniti <?= e($naslov) ?><?= $mst ? ' sa svom robom (' . e(stavki_tekst(count($mst))) . ')' : '' ?>?" aria-label="Ukloni <?= e($naslov) ?>" title="Ukloni mesto"><?= ikona('kanta') ?></button>
                </div>
            <?php endif; ?>
        </div>
        <div data-poruke></div>
        <?php if ($menja): ?>
            <details class="mesto-izmena">
                <summary><?= ikona('olovka', 'ikona ikona-mala') ?> Broj, kupac, adresa</summary>
                <div class="mesto-polja red-polja">
                    <div class="mesto-broj-polje">
                        <label for="m_broj_<?= (int)$mid ?>">Broj mesta</label>
                        <input class="polje" id="m_broj_<?= (int)$mid ?>" name="m_broj[<?= (int)$mid ?>]" form="izmene" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" value="<?= (int)$m['broj'] ?>">
                    </div>
                    <div class="mesto-kupac-polje">
                        <label for="m_kupac_<?= (int)$mid ?>">Kupac (nije obavezno)</label>
                        <input class="polje" id="m_kupac_<?= (int)$mid ?>" name="m_kupac[<?= (int)$mid ?>]" form="izmene" type="text" maxlength="120" list="lista-kupaca" value="<?= e($m['kupac'] ?? '') ?>">
                    </div>
                </div>
                <div class="red-polja">
                    <label for="m_adresa_<?= (int)$mid ?>">Adresa ili napomena (nije obavezno)</label>
                    <input class="polje" id="m_adresa_<?= (int)$mid ?>" name="m_adresa[<?= (int)$mid ?>]" form="izmene" type="text" maxlength="255" value="<?= e($m['adresa'] ?? '') ?>">
                </div>
                <button class="btn btn-braon btn-blok" type="submit" form="izmene" name="akcija" value="stavke">Sačuvaj</button>
            </details>
        <?php endif; ?>

        <?php if ($grupa['grupe'][0]['stavke']): ?>
            <ul class="lista stavke-lista"><?php foreach ($grupa['grupe'][0]['stavke'] as $st) { $prikazi_stavku($st); } ?></ul>
        <?php endif; ?>
        <?php foreach ($mpalete as $pl):
            $pid = (int)$pl['id'];
            $u_paleti = $grupa['grupe'][$pid]['stavke'] ?? [];
            $pz = spisak_zbir($u_paleti, $utovaren);
            $naslov_palete = paleta_naslov((string)$pl['naziv'], (int)$pl['broj']); ?>
            <div class="zbirna-paleta" id="paleta-<?= $pid ?>">
                <?php if ($menja): ?>
                    <details class="zbirna-uredi">
                        <summary class="zbirna-glava"><strong><?= ikona('stanje') ?> <?= e($naslov_palete) ?></strong><span class="stavka-olovka"><?= ikona('olovka', 'ikona ikona-mala') ?></span></summary>
                        <div class="zbirna-polja">
                            <label class="mala-oznaka zbirna-naziv">naziv palete<input class="polje" name="pl_naziv[<?= $pid ?>]" form="izmene" type="text" maxlength="60" required value="<?= e($pl['naziv']) ?>"></label>
                            <label class="mala-oznaka zbirna-broj">ovakvih paleta<input class="polje" name="pl_broj[<?= $pid ?>]" form="izmene" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" value="<?= (int)$pl['broj'] ?>"></label>
                            <button class="btn btn-mali btn-opasno" type="submit" form="izmene" name="ukloni_paletu" value="<?= $pid ?>" data-potvrda="Ukloniti zbirnu paletu „<?= e($pl['naziv']) ?>“ sa svim artiklima u njoj?" aria-label="Ukloni zbirnu paletu <?= e($pl['naziv']) ?>"><?= ikona('kanta') ?></button>
                        </div>
                    </details>
                <?php else: ?>
                    <div class="zbirna-glava"><strong><?= ikona('stanje') ?> <?= e($naslov_palete) ?></strong></div>
                <?php endif; ?>
                <div data-poruke></div>
                <p class="zbirna-zbir">Zbirna paleta<?= (int)$pl['broj'] > 1 ? ' × ' . (int)$pl['broj'] : '' ?> · <?= e(artikala_tekst(count($u_paleti))) ?><?= $pz['kg'] > 0 ? ' · ≈ ' . e(kg_tekst($pz['kg'])) : '' ?><?= (int)$pl['broj'] > 1 && $pz['kg'] > 0 ? ' ukupno' : '' ?></p>
                <?php if (!$u_paleti): ?>
                    <p class="pomoc bez-margine">Prazna – dodajte artikle (Dodaj robu → Gde ide: <?= e($pl['naziv']) ?>).</p>
                <?php else: ?>
                    <ul class="lista stavke-lista"><?php foreach ($u_paleti as $st) { $prikazi_stavku($st); } ?></ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (!$mst && !$mpalete): ?>
            <p class="pomoc mesto-prazno">Za ovo mesto još nema robe.</p>
        <?php elseif ($mst): ?>
            <p class="mesto-zbir">
                <?= e(stavki_tekst(count($mst))) ?>
                <?php if (round($mz['palete'], 1) > 0): ?> · <?= e(palete_zbir_tekst($mz['palete'])) ?><?php endif; ?>
                <?php if ($mz['kg'] > 0): ?> · ≈ <?= e(kg_tekst($mz['kg'])) ?><?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ($menja):
            $f = $forma !== null && $forma['mesto'] === (int)$mid ? $forma : ['vrsta' => '', 'polja' => []];
            $pf = $f['polja'];
            $za_dodaj = $f['vrsta'] === 'dodaj';
            $za_ostalo = $f['vrsta'] === 'ostalo';
            $za_zbirnu = $f['vrsta'] === 'zbirna';
            $otvori_dodaj = $za_dodaj || $za_ostalo || (!$za_zbirnu && (($aktivno_mesto === (int)$mid && !$otvori_zbirnu) || !$mst));
            $rr = $za_dodaj ? array_map(null, $pf['r_tekst'] ?? [], $pf['r_id'] ?? [], $pf['r_pal'] ?? [], $pf['r_pak'] ?? [], $pf['r_kom'] ?? []) : [];
            $rr = array_pad($rr, max(3, count($rr)), [null, null, null, null, null]);
            $u_paletu = $za_dodaj ? (int)($pf['paleta_id'] ?? 0) : $izabrana_paleta;
            $zr = $za_zbirnu ? array_map(null, $pf['z_artikal'] ?? [], $pf['z_id'] ?? [], $pf['z_kol'] ?? []) : [];
            $zr = array_pad($zr, max(3, count($zr)), [null, null, null]); ?>
            <details class="mesto-dodaj" id="dodaj-<?= (int)$mid ?>"<?= $otvori_dodaj ? ' open' : '' ?>>
                <summary class="btn btn-blok"><?= ikona('plus') ?> Dodaj robu za Mesto <?= (int)$m['broj'] ?></summary>
                <div data-poruke></div>
                <form method="post" autocomplete="off" class="mesto-forma" action="<?= e(url('admin/spisak.php')) ?>" data-dodavanje>
                    <?= csrf_polje() ?>
                    <input type="hidden" name="akcija" value="dodaj_vise">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="mesto_id" value="<?= (int)$mid ?>">
                    <?php if ($mpalete): ?>
                        <div class="red-polja">
                            <label for="paleta_id_<?= (int)$mid ?>">Gde ide</label>
                            <select class="polje" id="paleta_id_<?= (int)$mid ?>" name="paleta_id" data-odrediste>
                                <option value="0" data-broj="1">Posebne stavke (palete, paketi, komadi)</option>
                                <?php foreach ($mpalete as $pl): ?>
                                    <option value="<?= (int)$pl['id'] ?>" data-broj="<?= (int)$pl['broj'] ?>"<?= $u_paletu === (int)$pl['id'] ? ' selected' : '' ?>>U zbirnu paletu: <?= e(paleta_naslov((string)$pl['naziv'], (int)$pl['broj'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="pomoc bez-margine" data-pomoc-zbirna hidden>U zbirnu paletu upišite koliko ide na <strong>jednu</strong> paletu (paketi i/ili komadi).</p>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="paleta_id" value="0">
                    <?php endif; ?>
                    <p class="pomoc">Kucajte naziv (npr. <strong>humovit</strong>), dodirnite pakovanje i upišite količinu. Popunite koliko god redova treba, pa sve sačuvajte odjednom.</p>
                    <div class="roba-redovi" data-roba-redovi>
                        <?php foreach ($rr as $i => [$r_t, $r_id, $r_pal, $r_pak, $r_kom]): ?>
                            <div class="roba-red" data-roba-red data-popis data-po="0" data-pp="0" data-sada="0">
                                <span class="roba-broj" aria-hidden="true"><?= $i + 1 ?></span>
                                <?= polje_artikla('r_tekst[]', 'r_id[]', (string)$r_t, (int)$r_id, '', '', 'Artikal ' . ($i + 1), $i === 0 ? 'kucajte npr. humovit' : '') ?>
                                <div class="popis-polja roba-kol">
                                    <label class="mala-oznaka" data-polje-pal>paleta<input class="polje" name="r_pal[]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="0" data-p="pal" value="<?= e((string)$r_pal) ?>"></label>
                                    <label class="mala-oznaka" data-polje-pak>paketa<input class="polje" name="r_pak[]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="0" data-p="pak" value="<?= e((string)$r_pak) ?>"></label>
                                    <label class="mala-oznaka">komada<input class="polje" name="r_kom[]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" placeholder="0" data-p="kom" value="<?= e((string)$r_kom) ?>"></label>
                                </div>
                                <p class="popis-zbir" data-zbir aria-live="polite"></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-mali" data-roba-jos hidden><?= ikona('plus') ?> Još jedan artikal</button>
                    <button class="btn btn-primary btn-blok razmak-gore" type="submit">Sačuvaj robu za Mesto <?= (int)$m['broj'] ?></button>
                </form>
                <details class="mesto-ostalo"<?= $za_ostalo ? ' open' : '' ?>>
                    <summary>Ostalo (roba koja se ne vodi u aplikaciji)</summary>
                    <form method="post" autocomplete="off" action="<?= e(url('admin/spisak.php')) ?>">
                        <?= csrf_polje() ?>
                        <input type="hidden" name="akcija" value="ostalo">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="mesto_id" value="<?= (int)$mid ?>">
                        <p class="pomoc">Ova roba se samo upisuje na spisak (i u težinu tovara) – stanje u aplikaciji se ne menja.</p>
                        <div class="red-polja">
                            <label for="o_naziv_<?= (int)$mid ?>">Naziv</label>
                            <input class="polje" id="o_naziv_<?= (int)$mid ?>" name="o_naziv" type="text" maxlength="120" required placeholder="npr. Saksije 30 cm" value="<?= e($za_ostalo ? (string)($pf['o_naziv'] ?? '') : '') ?>">
                        </div>
                        <div class="red-polja-3 red-polja">
                            <div><label for="o_kolicina_<?= (int)$mid ?>">Količina</label><input class="polje" id="o_kolicina_<?= (int)$mid ?>" name="o_kolicina" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" required value="<?= e($za_ostalo ? (string)($pf['o_kolicina'] ?? '') : '') ?>"></div>
                            <div><label for="o_jedinica_<?= (int)$mid ?>">Jedinica</label><input class="polje" id="o_jedinica_<?= (int)$mid ?>" name="o_jedinica" type="text" maxlength="20" value="<?= e($za_ostalo ? (string)($pf['o_jedinica'] ?? 'kom') : 'kom') ?>" list="lista-jedinica"></div>
                            <div><label for="o_kg_<?= (int)$mid ?>">Kg po jedinici</label><input class="polje" id="o_kg_<?= (int)$mid ?>" name="o_kg" type="text" inputmode="decimal" maxlength="8" placeholder="nije obavezno" value="<?= e($za_ostalo ? (string)($pf['o_kg'] ?? '') : '') ?>"></div>
                        </div>
                        <button class="btn btn-braon btn-blok" type="submit">Dodaj ostalo</button>
                    </form>
                </details>
            </details>
            <details class="mesto-zbirna" id="zbirna-<?= (int)$mid ?>"<?= $za_zbirnu || ($otvori_zbirnu && $aktivno_mesto === (int)$mid) ? ' open' : '' ?>>
                <summary class="btn btn-blok"><?= ikona('stanje') ?> Zbirna paleta<span class="skriven-vizuelno"> za Mesto <?= (int)$m['broj'] ?></span></summary>
                <div data-poruke></div>
                <form method="post" autocomplete="off" class="mesto-forma" action="<?= e(url('admin/spisak.php')) ?>" data-zbirna>
                    <?= csrf_polje() ?>
                    <input type="hidden" name="akcija" value="nova_paleta">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="mesto_id" value="<?= (int)$mid ?>">
                    <p class="pomoc">Šta ide na <strong>jednu</strong> paletu: artikal i koliko – komada (<strong>25</strong>), deo palete (<strong>1/2</strong>) ili paketa (<strong>3 pak</strong>).</p>
                    <div class="zbirna-redovi" data-zbirna-redovi>
                        <div class="zbirna-red zbirna-red-naslov" aria-hidden="true"><span>Artikal</span><span>Na paleti</span></div>
                        <?php foreach ($zr as $i => [$z_art, $z_id, $z_kol]): ?>
                            <div class="zbirna-red" data-zbirna-red>
                                <?= polje_artikla('z_artikal[]', 'z_id[]', (string)$z_art, (int)$z_id, '', '', 'Artikal ' . ($i + 1), $i === 0 ? 'npr. rizla bela sitna' : '') ?>
                                <input class="polje" name="z_kol[]" type="text" maxlength="12" autocomplete="off" placeholder="<?= $i === 0 ? '1/2' : '' ?>" aria-label="Koliko na paleti, artikal <?= $i + 1 ?>" value="<?= e((string)$z_kol) ?>" data-zbirna-kol>
                                <p class="zbirna-info" data-zbirna-info aria-live="polite"></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-mali" data-zbirna-jos hidden><?= ikona('plus') ?> Još jedan artikal</button>
                    <div class="red-polja-2 red-polja razmak-gore">
                        <div><label for="p_broj_<?= (int)$mid ?>">Koliko ovakvih paleta</label><input class="polje" id="p_broj_<?= (int)$mid ?>" name="p_broj" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" value="<?= e($za_zbirnu ? (string)($pf['p_broj'] ?? '1') : '1') ?>"></div>
                        <div><label for="p_naziv_<?= (int)$mid ?>">Naziv (nije obavezno)</label><input class="polje" id="p_naziv_<?= (int)$mid ?>" name="p_naziv" type="text" maxlength="60" placeholder="Zbirna paleta" value="<?= e($za_zbirnu ? (string)($pf['p_naziv'] ?? '') : '') ?>"></div>
                    </div>
                    <button class="btn btn-primary btn-blok" type="submit">Dodaj zbirnu paletu</button>
                </form>
            </details>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if ($nacrt && $mesta): ?>
    <p class="mesta-sacuvaj"><button class="btn btn-braon btn-blok" type="submit" form="izmene" name="akcija" value="stavke">Sačuvaj izmene</button></p>
<?php endif; ?>

<?php if ($nacrt): ?>
    <form method="post" class="kartica" id="novo-mesto" autocomplete="off" action="<?= e(url('admin/spisak.php')) ?>">
        <?= csrf_polje() ?>
        <input type="hidden" name="akcija" value="dodaj_mesto">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="kartica-naslov"><h2><?= ikona('plus') ?> Dodaj mesto</h2></div>
        <div data-poruke></div>
        <p class="pomoc">Svako mesto istovara ima svoj broj (kao zaokružen broj na papiru) i svoju robu. Mesta dodajete koliko god treba; kupac nije obavezan.</p>
        <div class="mesto-polja red-polja">
            <div class="mesto-broj-polje">
                <label for="novo_broj">Broj mesta</label>
                <input class="polje" id="novo_broj" name="novo_broj" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" required value="<?= mesto_sledeci_broj($id) ?>" data-oznaci>
            </div>
            <div class="mesto-kupac-polje">
                <label for="kupac">Kupac (nije obavezno)</label>
                <input class="polje" id="kupac" name="novo_kupac" type="text" maxlength="120" list="lista-kupaca" placeholder="Upišite ime ili izaberite">
            </div>
        </div>
        <?php if ($nedavni): ?>
            <div class="izbor-mreza razmak-dole" aria-label="Raniji kupci">
                <?php foreach ($nedavni as $n): ?>
                    <button type="button" class="izbor-dugme" data-kupac="<?= e($n) ?>" aria-pressed="false"><?= e($n) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="red-polja">
            <label for="novo_adresa">Adresa ili napomena (nije obavezno)</label>
            <input class="polje" id="novo_adresa" name="novo_adresa" type="text" maxlength="255" placeholder="npr. Novi Sad, Futoški put 12">
        </div>
        <button class="btn btn-primary btn-blok" type="submit"><?= ikona('plus') ?> Dodaj mesto</button>
    </form>
    <datalist id="lista-kupaca"><?php foreach ($kupci as $k): ?><option value="<?= e($k['naziv']) ?>"><?php endforeach; ?></datalist>
    <datalist id="lista-jedinica"><option value="kom"><option value="vreća"><option value="paleta"><option value="paket"><option value="kg"></datalist>
    <?php if ($sku_unos): ?>
        <script type="application/json" id="sku-podaci"><?= sku_podaci_json($sku_unos) ?></script>
    <?php endif; ?>
<?php endif; ?>

<section class="kartica" aria-labelledby="akcije-naslov">
    <div class="kartica-naslov"><h2 id="akcije-naslov">Status spiska</h2></div>
    <?php
    $dugmad = [];
    if ($sp['status'] === 'nacrt') {
        $dugmad[] = ['za_utovar', 'Pošalji na utovar', 'btn-primary btn-veliko', 'Poslati spisak na utovar? Radnici će ga videti, a količine će biti rezervisane.' . ($manjak ? ' Pažnja: za neke stavke nema dovoljno slobodne robe.' : '')];
        $dugmad[] = ['otkazano', 'Otkaži spisak', 'btn-opasno', 'Otkazati ovaj spisak?'];
        $opis = 'Nacrt: menjajte slobodno. Stanje se ne dira, radnici ga ne vide.';
    } elseif ($sp['status'] === 'za_utovar') {
        $dugmad[] = ['nacrt', 'Vrati u nacrt (za izmene)', '', 'Vratiti spisak u nacrt? Radnici ga neće videti dok ga ponovo ne pošaljete.'];
        $dugmad[] = ['otkazano', 'Otkaži spisak', 'btn-opasno', 'Otkazati ovaj spisak? Rezervacija se ukida.'];
        $opis = 'Čeka utovar: radnici ga vide, količine su rezervisane. Stanje se skida tek kad se potvrdi utovar (radnik, ili vi dugmetom ispod).';
    } elseif ($sp['status'] === 'otkazano') {
        if ($sp['utovareno_u'] === null) {
            $dugmad[] = ['nacrt', 'Vrati u nacrt', '', 'Ponovo otvoriti otkazan spisak kao nacrt?'];
        }
        $opis = 'Otkazan spisak ništa ne rezerviše i ne menja stanje. Ako vam više ne treba, možete ga obrisati.';
    } else {
        $dugmad[] = ['otkazano', 'Otkaži utovar (vrati robu na stanje)', 'btn-opasno', 'Otkazati utovaren spisak? Sva utovarena roba se vraća na stanje (upisuje se kao povrat).'];
        $opis = 'Utovareno: stanje je skinuto i spisak se više ne menja. Ako je utovar greška, otkažite ga – roba se vraća na stanje.';
    }
    ?>
    <p class="pomoc"><?= e($opis) ?></p>
    <div class="grupa-dugmica">
        <?php foreach ($dugmad as [$novi, $tekst, $klasa, $potvrda]): ?>
            <form method="post" class="forma-u-liniji" action="<?= e(url('admin/spisak.php')) ?>" data-potvrda="<?= e($potvrda) ?>">
                <?= csrf_polje() ?>
                <input type="hidden" name="akcija" value="status">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="ocekivan" value="<?= e($sp['status']) ?>">
                <input type="hidden" name="novi" value="<?= e($novi) ?>">
                <button class="btn <?= e($klasa) ?>" type="submit"><?= e($tekst) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
    <?php if ($sp['status'] === 'za_utovar'): ?>
        <p class="razmak-gore"><a class="btn btn-braon btn-blok" href="<?= e(url('radnik/utovar.php?id=' . $id)) ?>"><?= ikona('kamion') ?> Potvrdi utovar (štikliranje stavki)</a></p>
    <?php endif; ?>
    <?php if ($sp['status'] === 'otkazano'): ?>
        <form method="post" class="razmak-gore" action="<?= e(url('admin/spisak.php')) ?>"
              data-potvrda="Trajno obrisati ovaj otkazan spisak? <?= $sp['utovareno_u'] !== null ? 'Njegov utovar i povrat biće označeni kao obrisani (stanje se ne menja). ' : '' ?>Ovo ne može da se vrati.">
            <?= csrf_polje() ?>
            <input type="hidden" name="akcija" value="obrisi_spisak">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-opasno btn-blok" type="submit"><?= ikona('kanta') ?> Obriši spisak</button>
        </form>
    <?php endif; ?>
</section>
<?php
ui_end();
