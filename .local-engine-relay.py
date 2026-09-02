import socket
import threading

SOCKET_PATH = "/var/www/html/.local-engine.sock"
LISTEN = ("127.0.0.1", 18787)


def pump(source, destination):
    try:
        while chunk := source.recv(65536):
            destination.sendall(chunk)
    except OSError:
        pass
    try:
        destination.shutdown(socket.SHUT_WR)
    except OSError:
        pass


def handle(client):
    upstream = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    try:
        upstream.connect(SOCKET_PATH)
        outbound = threading.Thread(target=pump, args=(client, upstream))
        inbound = threading.Thread(target=pump, args=(upstream, client))
        outbound.start()
        inbound.start()
        outbound.join()
        inbound.join()
    finally:
        upstream.close()
        client.close()


server = socket.socket()
server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
server.bind(LISTEN)
server.listen(16)
while True:
    connection, _ = server.accept()
    threading.Thread(target=handle, args=(connection,), daemon=True).start()
