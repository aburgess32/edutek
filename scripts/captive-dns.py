"""
EduPak Captive Portal DNS Server
Resolves ALL domain queries to the Beelink's local IP.
Students connect to WiFi, open any URL, and land on EduTek.
"""
import socket
import struct
import sys

LISTEN_IP = "0.0.0.0"
LISTEN_PORT = 53
REDIRECT_IP = None  # Auto-detect

def get_local_ip():
    """Get the Beelink's Ethernet IP."""
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("192.168.10.1", 80))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except:
        return "192.168.10.115"

def build_response(data, ip):
    """Build a DNS response that points to our IP."""
    response = bytearray(data[:2])  # Transaction ID
    response += b'\x81\x80'  # Flags: standard response, no error
    response += data[4:6]    # Questions count
    response += data[4:6]    # Answers count (same as questions)
    response += b'\x00\x00'  # Authority RRs
    response += b'\x00\x00'  # Additional RRs
    
    # Copy the question section
    pos = 12
    while pos < len(data):
        length = data[pos]
        if length == 0:
            pos += 5  # null byte + qtype(2) + qclass(2)
            break
        pos += length + 1
    response += data[12:pos]
    
    # Answer section
    response += b'\xc0\x0c'  # Pointer to domain name in question
    response += b'\x00\x01'  # Type A
    response += b'\x00\x01'  # Class IN
    response += b'\x00\x00\x00\x3c'  # TTL 60 seconds
    response += b'\x00\x04'  # Data length
    response += socket.inet_aton(ip)  # IP address
    
    return bytes(response)

def main():
    global REDIRECT_IP
    REDIRECT_IP = get_local_ip()
    print(f"EduPak Captive DNS starting...")
    print(f"Redirecting all DNS queries to {REDIRECT_IP}")
    print(f"Listening on {LISTEN_IP}:{LISTEN_PORT}")
    
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    try:
        sock.bind((LISTEN_IP, LISTEN_PORT))
    except PermissionError:
        print("ERROR: Run as Administrator to bind port 53")
        sys.exit(1)
    except OSError as e:
        print(f"ERROR: {e}")
        sys.exit(1)
    
    print("DNS server running. Press Ctrl+C to stop.")
    while True:
        try:
            data, addr = sock.recvfrom(512)
            response = build_response(data, REDIRECT_IP)
            sock.sendto(response, addr)
        except KeyboardInterrupt:
            break
        except Exception as e:
            print(f"Error: {e}")
    
    sock.close()
    print("DNS server stopped.")

if __name__ == "__main__":
    main()
