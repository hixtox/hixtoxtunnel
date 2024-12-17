package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io/ioutil"
	"log"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"

	"github.com/gorilla/websocket"
	"github.com/spf13/cobra"
	"gopkg.in/yaml.v2"
)

type Config struct {
	Token string `yaml:"token"`
}

var (
	cfgFile     string
	config      Config
	protocol    string
	localPort   int
	localHost   string
	serverHost  string = "http://16.170.173.161:8080"
	rootCmd     = &cobra.Command{
		Use:   "hixtunnel",
		Short: "Hixtunnel - Secure tunneling made easy",
	}
)

func main() {
	cfgFile = filepath.Join(os.Getenv("HOME"), ".hixtunnel", "config.yaml")

	rootCmd.PersistentFlags().StringVar(&serverHost, "server", serverHost, "Server host")
	
	configCmd := &cobra.Command{
		Use:   "config",
		Short: "Configure Hixtunnel",
		Run:   runConfig,
	}
	configCmd.Flags().StringVar(&config.Token, "token", "", "Authentication token")

	tunnelCmd := &cobra.Command{
		Use:   "tunnel",
		Short: "Start a tunnel",
		Run:   runTunnel,
	}
	tunnelCmd.Flags().StringVarP(&protocol, "protocol", "P", "tcp", "Protocol (tcp/http)")
	tunnelCmd.Flags().IntVarP(&localPort, "port", "p", 80, "Local port")
	tunnelCmd.Flags().StringVarP(&localHost, "host", "h", "localhost", "Local host")

	rootCmd.AddCommand(configCmd, tunnelCmd)

	if err := rootCmd.Execute(); err != nil {
		fmt.Println(err)
		os.Exit(1)
	}
}

func runConfig(cmd *cobra.Command, args []string) {
	if config.Token == "" {
		fmt.Println("Token is required")
		return
	}

	// Create config directory if it doesn't exist
	configDir := filepath.Dir(cfgFile)
	if err := os.MkdirAll(configDir, 0755); err != nil {
		log.Fatal(err)
	}

	// Save config
	data, err := yaml.Marshal(&config)
	if err != nil {
		log.Fatal(err)
	}

	if err := ioutil.WriteFile(cfgFile, data, 0644); err != nil {
		log.Fatal(err)
	}

	fmt.Println("Auth token saved and ready to go..")
}

func runTunnel(cmd *cobra.Command, args []string) {
	// Load config
	data, err := ioutil.ReadFile(cfgFile)
	if err != nil {
		log.Fatal("Please configure token first: hixtunnel config --token YOUR_TOKEN")
	}

	if err := yaml.Unmarshal(data, &config); err != nil {
		log.Fatal(err)
	}

	// Create tunnel
	tunnel, err := createTunnel()
	if err != nil {
		log.Fatal(err)
	}

	fmt.Printf("You are tunneling to \"%s\"\n", tunnel.PublicURL)

	// Handle tunnel connection
	interrupt := make(chan os.Signal, 1)
	signal.Notify(interrupt, os.Interrupt, syscall.SIGTERM)

	// Connect to WebSocket
	u := url.URL{Scheme: "ws", Host: "16.170.173.161:8080", Path: "/api/tunnel/ws"}
	q := u.Query()
	q.Set("tunnel_id", tunnel.ID)
	u.RawQuery = q.Encode()

	c, _, err := websocket.DefaultDialer.Dial(u.String(), nil)
	if err != nil {
		log.Fatal("dial:", err)
	}
	defer c.Close()

	// Create local connection
	local, err := net.Dial("tcp", fmt.Sprintf("%s:%d", localHost, localPort))
	if err != nil {
		log.Fatal(err)
	}
	defer local.Close()

	// Handle bidirectional traffic
	go func() {
		for {
			_, message, err := c.ReadMessage()
			if err != nil {
				log.Println("read:", err)
				return
			}
			if _, err := local.Write(message); err != nil {
				log.Println("write:", err)
				return
			}
		}
	}()

	go func() {
		buffer := make([]byte, 1024)
		for {
			n, err := local.Read(buffer)
			if err != nil {
				log.Println("read:", err)
				return
			}
			if err := c.WriteMessage(websocket.BinaryMessage, buffer[:n]); err != nil {
				log.Println("write:", err)
				return
			}
		}
	}()

	<-interrupt
	fmt.Println("\nClosing tunnel...")
}

type TunnelResponse struct {
	ID        string `json:"tunnel_id"`
	RemotePort int    `json:"remote_port"`
	PublicURL string `json:"public_url"`
}

func createTunnel() (*TunnelResponse, error) {
	data := map[string]interface{}{
		"protocol":   protocol,
		"local_port": localPort,
		"local_host": localHost,
		"token":      config.Token,
	}

	jsonData, err := json.Marshal(data)
	if err != nil {
		return nil, err
	}

	resp, err := http.Post(serverHost+"/api/tunnel/connect", "application/json", bytes.NewBuffer(jsonData))
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := ioutil.ReadAll(resp.Body)
		return nil, fmt.Errorf("server error: %s", body)
	}

	var tunnel TunnelResponse
	if err := json.NewDecoder(resp.Body).Decode(&tunnel); err != nil {
		return nil, err
	}

	return &tunnel, nil
}
