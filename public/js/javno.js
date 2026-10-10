// Meni na telefonu. Bez ovog skripta meni ostaje običan spisak veza ispod imena sajta.
(function () {
    document.documentElement.classList.add('js');

    document.addEventListener('DOMContentLoaded', function () {
        var dugme = document.querySelector('.dugme-meni');
        var meni = document.getElementById('meni');

        if (!dugme || !meni) {
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

        document.addEventListener('keydown', function (dogadjaj) {
            if (dogadjaj.key === 'Escape' && meni.classList.contains('otvoren')) {
                postavi(false);
                dugme.focus();
            }
        });
    });
})();
