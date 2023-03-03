#include <fstream>
#include <iostream>
#include <map>
#include <sstream>
#include <string>
#include <vector>

using branch_t = std::vector<std::string>;
using branches_t = std::vector<branch_t>;

struct CallGrapNode;
using CallGraph = std::map<std::string, CallGrapNode>;

struct CallGrapNode {
  CallGrapNode() {}
  CallGrapNode(const CallGraph &t) : children(t) {}
  CallGraph children;
};

using CallGraph = std::map<std::string, CallGrapNode>;

const char *ws = " \t\n\r\f\v";

inline std::string &rtrim(std::string &s, const char *t = ws) {
  s.erase(s.find_last_not_of(t) + 1);
  return s;
}

inline std::string &ltrim(std::string &s, const char *t = ws) {
  s.erase(0, s.find_first_not_of(t));
  return s;
}

inline std::string &trim(std::string &s, const char *t = ws) {
  return ltrim(rtrim(s, t), t);
}

void traverse_branches(const branches_t &branches) {
  std::cout << "number of branches:" << branches.size() << std::endl;
  for (const auto &branch : branches) {
    auto reversed_branch = branch;
    std::reverse(reversed_branch.begin(), reversed_branch.end());
    for (const auto &call : reversed_branch) {
      std::cout << call << std::endl;
    }
  }
}

void merge_subtree(CallGraph &callgraph, const branch_t &branch) {
  CallGraph *current = &callgraph;
  for (int index = 0; index != branch.size(); ++index) {
    CallGraph children;
    auto ins_res = current->insert(std::make_pair(branch[index], children));
    current = &ins_res.first->second.children;
  }
}

CallGraph merge_branches(const branches_t &branches) {
  CallGraph result;
  for (const auto &branch : branches) {
    auto reversed_branch = branch;
    std::reverse(reversed_branch.begin(), reversed_branch.end());
    merge_subtree(result, reversed_branch);
  }
  return result;
}

void traverse_graph(const CallGraph &graph, int &depth, int depthLimit) {
  if (depthLimit > -1 && depth > depthLimit) {
    return;
  }
  for (auto it = graph.begin(); it != graph.end(); it++) {
    for (int i = 0; i != depth; ++i) {
      std::cout << "  ";
    }
    std::cout << "|-" << it->first << std::endl;
    ++depth;
    traverse_graph(it->second.children, depth, depthLimit);
    --depth;
  }
}

void test() {
  CallGraph callgraph = {{"a", CallGrapNode({
                                   {"b", CallGrapNode({{"d", CallGrapNode()}})},
                                   {"c", CallGrapNode()},
                               })}};
  int depth = 0;
  CallGraph copy;
  branch_t b1 = {"a", "b", "c"};
  branch_t b2 = {"a", "c", "c"};
  merge_subtree(copy, b1);
  merge_subtree(copy, b2);
  traverse_graph(copy, depth, -1);
}

void generate_left_content(std::ofstream &out, const CallGraph &graph,
                           int &depth, int depthLimit) {
  if (depthLimit > -1 && depth > depthLimit) {
    return;
  }

  for (auto it = graph.begin(); it != graph.end(); it++) {
    if (it->first.empty()) {
      generate_left_content(out, it->second.children, depth, depthLimit);
      continue;
    }
    out << "\n<tr>";
    out << "\n  <td>";
    for (int i = 0; i != depth; ++i) {
      out << "&nbsp;&nbsp;";
    }
    out << "\n    <input type=\"checkbox\" id=\"" << it->first
        << "\" onchange=\"clicked()\" />";
    out << "" << it->first << "";
    out << "  </td>";
    out << "\n</tr>";
    ++depth;
    generate_left_content(out, it->second.children, depth, depthLimit);
    --depth;
  }
}

void generate_left(std::ofstream &out, const CallGraph &graph, int &depth,
                   int depthLimit) {
  out << "\n<div class=\"left\">";
  out << "\n<h1>CallGraph</h1>";
  out << "\n<table>";
  generate_left_content(out, graph, depth, depthLimit);
  out << "\n</table>";
  out << "\n</div>";
}

void generate_right(std::ofstream &out) {
  out << "\n<div class=\"right\">";
  out << "\n<h1>CodeView</h1>";
  out << "\n<div id=code_content>";
  out << "\n</div>";
  out << "\n</div>";
}

void generate_html(const CallGraph &graph, int &depth, int depthLimit) {
  std::ifstream in("lib/head_content.html");
  std::stringstream buffer;
  buffer << in.rdbuf();
  std::ofstream out("index_.html");
  out << buffer.str();
  out << "\n<body>\n";
  generate_left(out, graph, depth, depthLimit);
  generate_right(out);
  out << "\n</body>";
  out << "\n</html>";
}

int main(int argc, char *argv[]) {
  if (argc < 3) {
    std::cerr << "driver filename depth" << std::endl;
    return -1;
  }
  std::ifstream in(argv[1]);
  if (!in.is_open()) {
    std::cerr << "error : file not found" << std::endl;
    return -1;
  }
  std::cout << argv[1] << std::endl;
  std::string line;
  branches_t branches;
  while (std::getline(in, line)) {
    if (line.find("stack:") != std::string::npos) {
      branch_t branch;
      std::string call =
          line.substr(std::string("stack:").size(),
                      line.size() - std::string("stack:").size());
      branch.insert(branch.begin(), trim(call));
      branches.push_back(branch);
    } else {
      branches.back().push_back(trim(line));
    }
  }
  auto mergedGraph = merge_branches(branches);
  int depth = 0;
  traverse_graph(mergedGraph, depth, std::stoi(argv[2]));
  generate_html(mergedGraph, depth, std::stoi(argv[2]));
  return 0;
}
