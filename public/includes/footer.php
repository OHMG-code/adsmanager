            </div>
        </main>
        <!-- END: app-content -->
    </div>
    <!-- END: app-body -->
</div>
<!-- END: app-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    const sections = document.querySelectorAll('.nav-section');
    sections.forEach(section => {
        const header = section.querySelector('.nav-section-header');
        if (!header) return;
        header.addEventListener('click', () => {
            section.classList.toggle('is-open');
            const key = section.dataset.section;
            if (key) {
                localStorage.setItem('nav-section-' + key, section.classList.contains('is-open') ? '1' : '0');
            }
        });
    });

    sections.forEach(section => {
        const active = section.querySelector('.nav-link.active');
        if (active) {
            section.classList.add('is-open');
        } else if (section.dataset.section) {
            const stored = localStorage.getItem('nav-section-' + section.dataset.section);
            if (stored === '1') {
                section.classList.add('is-open');
            }
        }
    });
})();
</script>
</body>
</html>
