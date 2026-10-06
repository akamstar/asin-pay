// Commande keygen : génère une paire de clés Ed25519 pour signer les messages.
//
// La clé privée va dans la configuration du service de paiement,
// la clé publique dans celle du backend (vérification des signatures).
package main

import (
	"crypto/ed25519"
	"fmt"
	"os"

	"github.com/paiement-asin/payement/internal/signature"
)

func main() {
	pub, priv, err := ed25519.GenerateKey(nil)
	if err != nil {
		fmt.Fprintln(os.Stderr, "génération impossible :", err)
		os.Exit(1)
	}
	fmt.Println("# Service de paiement (secret, ne jamais partager)")
	fmt.Println("PAYMENT_SIGNING_PRIVATE_KEY=" + signature.EncodePrivateKey(priv))
	fmt.Println()
	fmt.Println("# Backend (clé publique de vérification)")
	fmt.Println("PAYMENT_SIGNING_PUBLIC_KEY=" + signature.EncodePublicKey(pub))
}
