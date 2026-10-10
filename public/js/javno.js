// Meni na telefonu. Bez ovog skripta meni ostaje običan spisak veza ispod imena sajta.
(function () {
    document.documentElement.classList.add('js');

    document.addEventListener('DOMContentLoaded', function () {
        var dugme = document.querySelector('.dugme-meni');
        var meni = document.getElementById('meni');
        var zaglavlje = document.querySelector('.zaglavlje');

        if (!dugme || !meni || !zaglavlje) {
            return;
        }

        function postavi(otvoren) {
            meni.classList.toggle('otvoren', otvoren);
            dugme.setAttribute('aria-expanded', otvoren ? 'true' : 'false');
        }

        dugme.addEventListener('click', function () {
            postavi(!meni.classList.contains('otvoren'));
        });

        meni.addEventListener('click', function (dogadjaj) {
            if (dogadjaj.target.closest('a')) {
                postavi(false);
            }
        });

        // Ploča se zatvara i kad fokus ili dodir izađe iz zaglavlja; inače Tab posle poslednje veze završi ispod ploče.
        function izvanZaglavlja(dogadjaj) {
            if (meni.classList.contains('otvoren') && !zaglavlje.contains(dogadjaj.target)) {
                postavi(false);
            }
        }

        document.addEventListener('focusin', izvanZaglavlja);
        document.addEventListener('click', izvanZaglavlja);

        document.addEventListener('keydown', function (dogadjaj) {
            if (dogadjaj.key === 'Escape' && meni.classList.contains('otvoren')) {
                postavi(false);
                dugme.focus();
            }
        });
    });

    // Zaglavlje: na vrhu strane providno preko naslovnog dela, posle skrolovanja staklo, uz liniju napretka čitanja. Bez ovog skripta je odmah staklo.
    document.addEventListener('DOMContentLoaded', function () {
        var zaglavlje = document.querySelector('.zaglavlje');
        var linija = document.querySelector('.linija');

        if (!zaglavlje) {
            return;
        }

        function osvezi() {
            var vrh = window.scrollY;
            var najvise = document.documentElement.scrollHeight - window.innerHeight;

            zaglavlje.classList.toggle('skrolovano', vrh > 40);

            if (linija) {
                linija.style.opacity = vrh > 40 ? '1' : '0';
                linija.style.transform = 'scaleX(' + (najvise > 0 ? Math.min(1, vrh / najvise) : 0) + ')';
            }
        }

        window.addEventListener('scroll', osvezi, { passive: true });
        osvezi();
    });

    // Plutajući pomoćnik: prozor na istoj strani. Bez ovog skripta dugme je obična veza ka strani pomoćnika, a prozor ostaje skriven.
    document.addEventListener('DOMContentLoaded', function () {
        var prozor = document.getElementById('pomocnik-prozor');

        if (!prozor) {
            return;
        }

        var forma = prozor.querySelector('[data-pomocnik-forma]');
        var polje = forma.querySelector('textarea');
        var dugmePitaj = forma.querySelector('button[type="submit"]');
        var rezultat = prozor.querySelector('[data-pomocnik-rezultat]');
        var zatvori = prozor.querySelector('[data-pomocnik-zatvori]');
        var otvaraci = document.querySelectorAll('[data-pomocnik-otvori]');
        var pozadina = document.querySelectorAll('.zaglavlje, main');
        var telefon = window.matchMedia('(max-width: 719px)');
        var zadnjiOtvarac = null;

        function jeOtvoren() {
            return !prozor.hidden;
        }

        function otvori(otvarac) {
            zadnjiOtvarac = otvarac || zadnjiOtvarac;
            prozor.hidden = false;
            document.documentElement.classList.add('pomocnik-otvoren');
            otvaraci.forEach(function (o) {
                o.setAttribute('aria-expanded', 'true');
            });

            // Na telefonu prozor zauzima ceo ekran: strana iza njega se gasi za fokus i čitač ekrana.
            if (telefon.matches) {
                prozor.setAttribute('aria-modal', 'true');
                pozadina.forEach(function (e) {
                    e.inert = true;
                });
            }

            polje.focus();
        }

        function zatvoriProzor() {
            if (!jeOtvoren()) {
                return;
            }

            prozor.hidden = true;
            document.documentElement.classList.remove('pomocnik-otvoren');
            prozor.removeAttribute('aria-modal');
            pozadina.forEach(function (e) {
                e.inert = false;
            });
            otvaraci.forEach(function (o) {
                o.setAttribute('aria-expanded', 'false');
            });

            // Fokus se vraća onome ko je otvorio prozor; ako je to stavka sklopljenog menija (na telefonu je nevidljiva), dugmetu menija, a inače dugmetu na strani.
            var vidljiv = function (e) {
                return e && e.offsetParent !== null;
            };
            var meta = [zadnjiOtvarac, document.querySelector('.dugme-meni'), document.querySelector('.plutajuci-dugme')].filter(vidljiv)[0];

            if (meta) {
                meta.focus();
            }
        }

        otvaraci.forEach(function (o) {
            o.setAttribute('aria-haspopup', 'dialog');
            o.setAttribute('aria-expanded', 'false');
            o.addEventListener('click', function (dogadjaj) {
                dogadjaj.preventDefault();
                otvori(o);
            });
        });

        zatvori.addEventListener('click', zatvoriProzor);

        // „Napiši moj": pitanja šablona stižu u isti prozor, a napisan tekst u njegov rezultat. Ako ne uspe, veza i obrazac rade
        // običnim putem, na strani pomoćnika.
        function zatrazi(adresa, opcije) {
            return fetch(
                adresa,
                Object.assign({ headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' }, opcije)
            ).then(function (odgovor) {
                if ((odgovor.headers.get('Content-Type') || '').indexOf('text/html') === -1 || odgovor.status >= 500 || odgovor.status === 419 || odgovor.status === 404) {
                    throw new Error('odgovor nije upotrebljiv');
                }

                return odgovor.text();
            });
        }

        document.querySelectorAll('[data-pomocnik-sablon]').forEach(function (v) {
            v.setAttribute('aria-haspopup', 'dialog');
        });

        document.addEventListener('click', function (dogadjaj) {
            var veza = dogadjaj.target.closest('[data-pomocnik-sablon]');

            if (!veza) {
                return;
            }

            dogadjaj.preventDefault();
            otvori(jeOtvoren() ? null : veza);
            rezultat.textContent = prozor.getAttribute('data-trazim');

            zatrazi(veza.href)
                .then(function (html) {
                    rezultat.innerHTML = html;
                    prikaziKopiraj(rezultat);
                    (rezultat.querySelector('input:not([type="hidden"]), textarea') || rezultat).focus();
                })
                .catch(function () {
                    window.location.href = veza.href;
                });
        });

        rezultat.addEventListener('submit', function (dogadjaj) {
            var obrazac = dogadjaj.target.closest('[data-pomocnik-sablon-forma]');

            if (!obrazac) {
                return;
            }

            dogadjaj.preventDefault();
            obrazac.querySelector('button[type="submit"]').disabled = true;
            prozor.setAttribute('aria-busy', 'true');

            var stanje = document.createElement('p');
            stanje.className = 'prazno';
            stanje.setAttribute('role', 'status');
            stanje.textContent = prozor.getAttribute('data-trazim');
            obrazac.parentNode.insertBefore(stanje, obrazac);

            zatrazi(obrazac.action, { method: 'POST', body: new FormData(obrazac) })
                .then(function (html) {
                    rezultat.innerHTML = html;
                    prikaziKopiraj(rezultat);
                    // Napisan tekst je dug: prozor se pomera na njegov početak, a ne na kraj.
                    rezultat.focus({ preventScroll: true });
                    rezultat.scrollIntoView({ block: 'start' });
                })
                .catch(function () {
                    obrazac.submit();
                })
                .then(function () {
                    prozor.removeAttribute('aria-busy');
                });
        });

        document.addEventListener('keydown', function (dogadjaj) {
            if (dogadjaj.key === 'Escape' && jeOtvoren()) {
                zatvoriProzor();
            }
        });

        // Na telefonu Tab ne sme da izađe iz prozora.
        prozor.addEventListener('keydown', function (dogadjaj) {
            if (dogadjaj.key !== 'Tab' || !telefon.matches) {
                return;
            }

            var mesta = Array.prototype.filter.call(
                prozor.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled])'),
                function (e) {
                    return e.offsetParent !== null;
                }
            );
            var prvo = mesta[0];
            var poslednje = mesta[mesta.length - 1];

            if (dogadjaj.shiftKey && document.activeElement === prvo) {
                dogadjaj.preventDefault();
                poslednje.focus();
            } else if (!dogadjaj.shiftKey && document.activeElement === poslednje) {
                dogadjaj.preventDefault();
                prvo.focus();
            }
        });

        // Isto pitanje, ista pravila i ista provera kao na strani pomoćnika; šalje se isti obrazac, a odgovor stiže bez okvira strane. Ako ne uspe,
        // obrazac se šalje običnim putem i odgovor se pokazuje na strani pomoćnika.
        forma.addEventListener('submit', function (dogadjaj) {
            dogadjaj.preventDefault();
            dugmePitaj.disabled = true;
            prozor.setAttribute('aria-busy', 'true');
            rezultat.textContent = prozor.getAttribute('data-trazim');

            fetch(forma.action, {
                method: 'POST',
                body: new FormData(forma),
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                credentials: 'same-origin',
            })
                .then(function (odgovor) {
                    if ((odgovor.headers.get('Content-Type') || '').indexOf('text/html') === -1 || odgovor.status >= 500 || odgovor.status === 419) {
                        throw new Error('odgovor nije upotrebljiv');
                    }

                    return odgovor.text();
                })
                .then(function (html) {
                    rezultat.innerHTML = html;
                    rezultat.focus();
                })
                .catch(function () {
                    forma.submit();
                })
                .then(function () {
                    dugmePitaj.disabled = false;
                    prozor.removeAttribute('aria-busy');
                });
        });
    });

    // Dugme „Kopiraj": kopira tekst iz elementa na koji pokazuje data-kopiraj. Vidi se tek kad skript radi; bez međuspremnika tekst se samo označi.
    function prikaziKopiraj(koren) {
        (koren || document).querySelectorAll('[data-kopiraj]').forEach(function (dugme) {
            dugme.hidden = false;
        });
    }

    window.prikaziKopiraj = prikaziKopiraj;

    document.addEventListener('DOMContentLoaded', function () {
        prikaziKopiraj(document);
    });

    document.addEventListener('click', function (dogadjaj) {
        var dugme = dogadjaj.target.closest('[data-kopiraj]');
        var izvor = dugme ? document.getElementById(dugme.getAttribute('data-kopiraj')) : null;

        if (!dugme || !izvor) {
            return;
        }

        var naziv = dugme.textContent;

        function gotovo() {
            dugme.textContent = dugme.getAttribute('data-kopirano');
            window.setTimeout(function () {
                dugme.textContent = naziv;
            }, 2000);
        }

        function oznaci() {
            var opseg = document.createRange();
            opseg.selectNodeContents(izvor);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(opseg);

            try {
                if (document.execCommand('copy')) {
                    gotovo();
                }
            } catch (greska) {
                // Tekst ostaje označen; čovek ga kopira sam.
            }
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(izvor.textContent).then(gotovo, oznaci);
        } else {
            oznaci();
        }
    });
})();
