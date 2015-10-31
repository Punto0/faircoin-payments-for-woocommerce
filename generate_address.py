#!/usr/bin/python
import sys
import subprocess
from pycoin.key import Key
from pycoin.key.electrum import ElectrumWallet
from pycoin.key.validate import netcode_and_type_for_data, netcode_and_type_for_text, is_address_valid, is_wif_valid, is_public_bip32_valid
from pycoin.key.BIP32Node import BIP32Node

#MPK = "xpub661MyMwAqRbcF5XLtpkUDKQiK6E1csCmpzaSM2kJx7NeXB94hMxwSRtnnm3wWKpPuVQymznZCYpM6zVZF3Zr5AxGC2DqGVvbLrrFJtS2BgY"

#print 'Number of arguments:', len(sys.argv), 'arguments.'
#print 'Argument List:', str(sys.argv)
mpk = sys.argv[1]
index = int(sys.argv[2])

#print(is_public_bip32_valid(MPK))

wallet = BIP32Node.from_hwif(mpk)

key = wallet.subkey(0)

subkey = key.subkey(index)
calculated_address = subkey.address()
print("%s" % calculated_address)
sys.exit(calculated_address)






