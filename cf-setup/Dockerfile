# cf-setup M1 (Hülle): Assistent mit Startcode und Vorprüfung. Installiert noch nichts.
# Basis: docker:cli bringt Docker-Client und Compose mit. Für Auslieferungen wird BASIS auf einen festen Digest gesetzt:
#   docker build --build-arg BASIS=docker@sha256:<digest> -t cf-setup:<version> .
ARG BASIS=docker:cli
FROM ${BASIS}

ARG PHP=php84
RUN apk add --no-cache ${PHP} ${PHP}-session ${PHP}-ctype ${PHP}-gd ${PHP}-openssl ${PHP}-mbstring \
    && ln -sf /usr/bin/${PHP} /usr/local/bin/php \
    && mkdir -p /opt/cf-setup

COPY bin /opt/cf-setup/bin
COPY src /opt/cf-setup/src
COPY lang /opt/cf-setup/lang
COPY public /opt/cf-setup/public
COPY entrypoint.sh /opt/cf-setup/entrypoint.sh
RUN chmod 0755 /opt/cf-setup/entrypoint.sh \
    && for f in /opt/cf-setup/bin/*.php /opt/cf-setup/src/*.php /opt/cf-setup/lang/*.php /opt/cf-setup/public/*.php; do php -l "$f" >/dev/null || exit 1; done

EXPOSE 8080
ENTRYPOINT ["/opt/cf-setup/entrypoint.sh"]
