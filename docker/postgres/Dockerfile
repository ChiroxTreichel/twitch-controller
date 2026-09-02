# Basisimage
FROM postgres:16.3

# Locale "de_DE.UTF-8" aktivieren
RUN apt-get update && apt-get install -y --no-install-recommends locales \
    && sed -i 's/^# *de_DE.UTF-8 UTF-8/de_DE.UTF-8 UTF-8/' /etc/locale.gen \
    && locale-gen \
    && update-locale LANG=de_DE.UTF-8 \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Setze Standard-Umgebung für Postgres
ENV LANG=de_DE.UTF-8 \
    LANGUAGE=de_DE:de \
    LC_ALL=de_DE.UTF-8
