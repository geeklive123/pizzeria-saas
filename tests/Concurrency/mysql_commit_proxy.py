"""Test-only local proxy: drop one confirmed COMMIT response, after MySQL replies."""
import select
import socket
import sys
import threading
from pathlib import Path

port_file, dropped_file = map(Path, sys.argv[1:3])
listener = socket.socket()
listener.bind(('127.0.0.1', 0))
listener.listen()
listener.settimeout(0.5)
port_tmp = port_file.with_name('port.tmp')
port_tmp.write_text(str(listener.getsockname()[1]), encoding='ascii')
port_tmp.replace(port_file)
armed = threading.Event()


def relay(client):
    server = socket.create_connection(('127.0.0.1', 3306), timeout=10)
    client.settimeout(None)
    server.settimeout(None)
    buffers = {client: b'', server: b''}
    commits = 0
    awaiting_commit = False
    try:
        while True:
            readable, _, _ = select.select([client, server], [], [], 60)
            if not readable:
                break
            for source in readable:
                data = source.recv(65536)
                if not data:
                    return
                buffers[source] += data
                while len(buffers[source]) >= 4:
                    length = int.from_bytes(buffers[source][:3], 'little')
                    if len(buffers[source]) < length + 4:
                        break
                    packet, buffers[source] = buffers[source][:length + 4], buffers[source][length + 4:]
                    body = packet[4:]
                    if source is client and body[:1] == b'\x03' and body[1:].strip().upper() == b'COMMIT':
                        commits += 1
                        if commits == 2 and not armed.is_set():
                            awaiting_commit = True
                    if source is server and awaiting_commit:
                        if body[:1] != b'\x00':
                            raise RuntimeError('MySQL did not confirm COMMIT')
                        armed.set()
                        dropped_file.write_text('mysql_ok_received_response_dropped', encoding='ascii')
                        return
                    (server if source is client else client).sendall(packet)
    finally:
        client.close()
        server.close()


while not port_file.with_name('stop').exists():
    try:
        connection, _ = listener.accept()
    except socket.timeout:
        continue
    threading.Thread(target=relay, args=(connection,), daemon=True).start()
listener.close()
