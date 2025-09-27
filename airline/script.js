document.addEventListener('DOMContentLoaded', function() {
        function setupShowMoreButton(buttonId, hiddenClass) {
            const btn = document.getElementById(buttonId);
            if (!btn) return;

            btn.addEventListener('click', function() {
                const hiddenRows = document.querySelectorAll('.' + hiddenClass);
                if (hiddenRows.length === 0) return;

                let isHidden = hiddenRows[0].style.display === 'none' || !hiddenRows[0].style.display;
                hiddenRows.forEach(row => {
                    row.style.display = isHidden ? 'table-row' : 'none';
                });

                btn.textContent = isHidden ? 'Show Less Flights' : 'Show More Flights';
            });

            const hiddenRows = document.querySelectorAll('.' + hiddenClass);
            hiddenRows.forEach(row => row.style.display = 'none');
        }

        setupShowMoreButton('showMoreBtnAvailable', 'hidden-flights');
        setupShowMoreButton('showMoreBtnAll', 'hidden-flights');
    });
document.addEventListener('DOMContentLoaded', function () {
        const loginBtn = document.getElementById('loginBtn');
        const signupBtn = document.getElementById('signupBtn');
        const loginModal = document.getElementById('loginModal');
        const signupModal = document.getElementById('signupModal');
        const loginClose = document.getElementById('loginClose');
        const signupClose = document.getElementById('signupClose');

        loginBtn?.addEventListener('click', () => { loginModal.style.display = 'block'; });
        signupBtn?.addEventListener('click', () => { signupModal.style.display = 'block'; });
        loginClose?.addEventListener('click', () => { loginModal.style.display = 'none'; });
        signupClose?.addEventListener('click', () => { signupModal.style.display = 'none'; });

        window.addEventListener('click', (event) => {
            if (event.target === loginModal) loginModal.style.display = 'none';
            if (event.target === signupModal) signupModal.style.display = 'none';
        });
    });
document.addEventListener('DOMContentLoaded', function () {
        const paymentModal = document.getElementById('paymentModal');
        const paymentClose = document.getElementById('paymentClose');

        if (paymentModal && paymentModal.style.display === 'block') {
            paymentModal.style.display = 'block';
        }

        paymentClose?.addEventListener('click', () => {
            paymentModal.style.display = 'none';
        });

        window.addEventListener('click', (event) => {
            if (event.target === paymentModal) paymentModal.style.display = 'none';
        });
    });