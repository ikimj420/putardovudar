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
})();
