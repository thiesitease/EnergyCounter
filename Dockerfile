FROM python:3.12-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY server.py .
COPY static ./static
# Startdaten (Ablesungen aus der Excel, Zähler-Konfiguration) – beim ersten Start ins Volume kopiert
COPY data ./data-seed
ENV ZB_DATA=/data PORT=8080
EXPOSE 8080
CMD ["sh", "-c", "mkdir -p /data && [ -f /data/readings.json ] || cp -n data-seed/*.json /data/; python server.py"]
